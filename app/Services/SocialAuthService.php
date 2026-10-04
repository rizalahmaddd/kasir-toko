<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SocialAuthService
{
    public function __construct(private TenantProvisioner $provisioner) {}

    /**
     * Apakah Google Sign-In aktif dikonfigurasi di backend.
     */
    public function googleConfigured(): bool
    {
        return ! empty(config('services.google.client_id'));
    }

    /**
     * Apakah Apple Sign-In aktif dikonfigurasi di backend.
     */
    public function appleConfigured(): bool
    {
        return ! empty(config('services.apple.bundle_id')) || ! empty(config('services.apple.client_id'));
    }

    /**
     * Konfigurasi ketersediaan auth sosial untuk client (Web & Mobile).
     *
     * @return array{
     *     google: array{enabled: bool, client_id: ?string},
     *     apple: array{enabled: bool, bundle_id: ?string},
     *     whatsapp_otp: array{enabled: bool}
     * }
     */
    public function authConfig(): array
    {
        return [
            'google' => [
                'enabled' => $this->googleConfigured(),
                'client_id' => config('services.google.client_id') ?: null,
            ],
            'apple' => [
                'enabled' => $this->appleConfigured(),
                'bundle_id' => config('services.apple.bundle_id') ?: config('services.apple.client_id') ?: null,
            ],
            'whatsapp_otp' => [
                'enabled' => filled(config('services.fonnte.token')),
            ],
        ];
    }

    /**
     * Verifikasi ID Token Google / Firebase.
     *
     * @return array{sub: string, email: string, name: ?string, picture: ?string}
     */
    public function verifyGoogleToken(string $idToken): array
    {
        if (blank($idToken)) {
            throw ValidationException::withMessages(['id_token' => 'Token Google wajib diisi.']);
        }

        try {
            // Verifikasi via Google TokenInfo API resmi
            $response = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $idToken,
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Google tokeninfo response invalid: '.$response->body());
            }

            $payload = $response->json();

            $sub = $payload['sub'] ?? $payload['user_id'] ?? null;
            $email = $payload['email'] ?? null;

            if (! $sub || ! $email) {
                throw new \RuntimeException('Token Google tidak memuat identitas pengguna.');
            }

            $emailVerified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (! $emailVerified) {
                throw ValidationException::withMessages(['id_token' => 'Email Google belum diverifikasi.']);
            }

            return [
                'sub' => (string) $sub,
                'email' => strtolower(trim((string) $email)),
                'name' => $payload['name'] ?? null,
                'picture' => $payload['picture'] ?? null,
            ];
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Google ID Token verification failed: '.$e->getMessage());
            throw ValidationException::withMessages(['id_token' => 'Token Google tidak valid atau sudah kadaluarsa.']);
        }
    }

    /**
     * Verifikasi Identity Token Apple (JWT) menggunakan Apple JWKS.
     *
     * @return array{sub: string, email: ?string, email_verified: bool}
     */
    public function verifyAppleToken(string $identityToken): array
    {
        if (blank($identityToken)) {
            throw ValidationException::withMessages(['identity_token' => 'Token Apple wajib diisi.']);
        }

        try {
            // Ambil public keys Apple (cache 24 jam)
            $jwks = Cache::remember('apple_auth_jwks', 86400, function () {
                $res = Http::timeout(10)->get('https://appleid.apple.com/auth/keys');
                if (! $res->successful()) {
                    throw new \RuntimeException('Gagal mengambil Apple public keys.');
                }

                return $res->json();
            });

            $keys = JWK::parseKeySet($jwks);
            $decoded = JWT::decode($identityToken, $keys);
            $payload = (array) $decoded;

            if (($payload['iss'] ?? '') !== 'https://appleid.apple.com') {
                throw new \RuntimeException('Issuer Apple tidak valid.');
            }

            $sub = $payload['sub'] ?? null;
            if (! $sub) {
                throw new \RuntimeException('Token Apple tidak memuat sub/identitas.');
            }

            $email = isset($payload['email']) ? strtolower(trim((string) $payload['email'])) : null;
            $emailVerified = filter_var($payload['email_verified'] ?? true, FILTER_VALIDATE_BOOLEAN);

            return [
                'sub' => (string) $sub,
                'email' => $email,
                'email_verified' => $emailVerified,
            ];
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Apple Identity Token verification failed: '.$e->getMessage());
            throw ValidationException::withMessages(['identity_token' => 'Token Apple tidak valid atau sudah kadaluarsa.']);
        }
    }

    /**
     * Cari akun berdasarkan ID sosial atau email. Jika belum ada, buat toko baru + akun pemilik.
     *
     * @param  'google'|'apple'  $provider
     * @param  array{sub: string, email?: ?string, name?: ?string, picture?: ?string}  $payload
     */
    public function findOrCreateUser(string $provider, array $payload, ?string $shopName = null): User
    {
        $idColumn = $provider === 'google' ? 'google_id' : 'apple_id';
        $sub = $payload['sub'];
        $email = $payload['email'] ?? null;

        // 1. Cari berdasarkan ID provider sosial
        $user = User::withoutGlobalScopes()->where($idColumn, $sub)->first();
        if ($user) {
            return $user;
        }

        // 2. Jika ada email, cari akun yang sudah ada (Auto-linking)
        if ($email) {
            $user = User::withoutGlobalScopes()->where('email', $email)->first();
            if ($user) {
                $user->{$idColumn} = $sub;
                if (empty($user->avatar) && ! empty($payload['picture'])) {
                    $user->avatar = $payload['picture'];
                }
                if ($user->email_verified_at === null) {
                    $user->email_verified_at = now();
                }
                $user->save();

                return $user;
            }
        }

        // 3. User baru: buat toko baru beserta pemiliknya
        return DB::transaction(function () use ($idColumn, $sub, $payload, $shopName, $provider) {
            $name = ! empty($payload['name']) ? trim($payload['name']) : ($provider === 'apple' ? 'Pengguna Apple' : 'Pengguna Google');
            $email = $payload['email'] ?? ($sub.'@'.($provider === 'apple' ? 'privaterelay.appleid.com' : 'google.user'));

            $targetShopName = filled($shopName)
                ? trim($shopName)
                : 'Toko '.Str::headline(explode(' ', $name)[0] ?? 'Baru');

            $username = $this->generateUniqueUsername($name, $email);

            ['owner' => $owner] = $this->provisioner->provision(
                $targetShopName,
                [
                    'name' => $name,
                    'username' => $username,
                    'email' => $email,
                    'password' => Hash::make(Str::random(32)),
                ],
            );

            $owner->forceFill([
                $idColumn => $sub,
                'avatar' => $payload['picture'] ?? null,
                'email_verified_at' => now(),
            ])->save();

            return $owner;
        });
    }

    /**
     * Hapus akun pengguna dan bersihkan hak akses / toko jika pengguna adalah pemilik tunggal.
     */
    public function deleteAccount(User $user): void
    {
        DB::transaction(function () use ($user) {
            $tenant = $user->tenant;

            // Cabut semua token sesi API
            $user->tokens()->delete();

            // Catat log audit sebelum dihapus
            activity('auth')
                ->causedBy($user)
                ->performedOn($user)
                ->event('account_deleted')
                ->log("Akun {$user->email} (ID: {$user->id}) dihapus oleh pengguna.");

            // Hapus pengguna
            $user->delete();

            // Jika pemilik toko dan tidak ada akun lain di toko tersebut, suspend tenant
            if ($tenant && $tenant->users()->count() <= 1) {
                $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
            }
        });
    }

    /**
     * Generate username yang unik, diawali huruf, dan sesuai aturan User::usernameRules.
     */
    private function generateUniqueUsername(string $name, string $email): string
    {
        $base = Str::slug(explode('@', $email)[0] ?: $name, '');
        // Pastikan diawali huruf
        if (! preg_match('/^[a-z]/', $base)) {
            $base = 'u'.$base;
        }
        $base = substr($base, 0, 15);
        if (strlen($base) < 3) {
            $base = 'user'.Str::lower(Str::random(4));
        }

        $candidate = $base;
        $counter = 1;

        while (User::withoutGlobalScopes()->where('username', $candidate)->exists()) {
            $candidate = substr($base, 0, 12).$counter;
            $counter++;
        }

        return $candidate;
    }
}
