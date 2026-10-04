<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\SendOtpRequest;
use App\Http\Requests\Api\V1\Auth\VerifyOtpRequest;
use App\Http\Resources\V1\Auth\CurrentUserResource;
use App\Http\Resources\V1\Auth\OtpChallengeResource;
use App\Http\Resources\V1\Auth\TokenResource;
use App\Models\User;
use App\Services\SocialAuthService;
use App\Services\TenantProvisioner;
use App\Services\WhatsAppOtpService;
use App\Support\CurrentTenant;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use App\Support\SaasSettings;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

#[ApiTag('Autentikasi', 'Akun', 'Login dengan password atau OTP WhatsApp untuk mendapatkan token Bearer.')]
class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    /**
     * Login dengan password.
     *
     * `login` boleh berisi username, email, atau nomor HP (jenisnya ditebak dari isinya). Setelah
     * 5 kali gagal, login dari identitas + IP yang sama dikunci sementara.
     */
    public function login(LoginRequest $request): TokenResource
    {
        $throttleKey = $request->throttleKey();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            event(new Lockout($request));
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'login' => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
            ]);
        }

        $credentials = $request->identifier();
        $user = User::query()->where($credentials)->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            RateLimiter::hit($throttleKey);
            event(new Failed('sanctum', $user, $credentials));

            throw ValidationException::withMessages(['login' => trans('auth.failed')]);
        }

        RateLimiter::clear($throttleKey);

        return $this->issueToken($user, $request->string('device_name'));
    }

    /**
     * Kirim OTP WhatsApp.
     *
     * Mengirim kode 6 digit ke nomor WhatsApp akun. Respons sama untuk login yang tidak terdaftar
     * (tanpa pesan terkirim), jadi tampilkan "kalau akun terdaftar, kode dikirim". Simpan
     * `otp_token` dari respons untuk langkah verifikasi. Kode berlaku 5 menit; kirim ulang baru boleh setelah `cooldown_seconds`.
     */
    public function sendOtp(SendOtpRequest $request, WhatsAppOtpService $otp): OtpChallengeResource
    {
        $result = $this->withLoginErrorKey(fn () => $otp->sendOtp($request->string('login')));

        return new OtpChallengeResource([
            'otp_token' => Crypt::encryptString($result['challenge']),
            'masked_phone' => $result['masked_phone'],
            'cooldown_seconds' => $result['cooldown_seconds'],
        ]);
    }

    /**
     * Verifikasi OTP WhatsApp.
     *
     * Menukar `otp_token` + kode OTP dengan token Bearer. Setelah 5 kali salah, kode dibatalkan
     * dan harus diminta ulang.
     */
    public function verifyOtp(VerifyOtpRequest $request, WhatsAppOtpService $otp): TokenResource
    {
        try {
            $challenge = Crypt::decryptString((string) $request->validated('otp_token'));
        } catch (DecryptException) {
            throw ValidationException::withMessages(['otp_token' => 'Sesi OTP tidak valid. Silakan minta kode baru.']);
        }

        $user = $otp->verifyOtp($challenge, $request->string('otp'));

        return $this->issueToken($user, $request->string('device_name'));
    }

    /**
     * Daftar toko baru.
     *
     * Membuat toko (masa uji coba sesuai konfigurasi), peran bawaan, dan akun pemiliknya, lalu
     * langsung mengembalikan token Bearer seperti login.
     */
    #[ApiResponse(TokenResource::class, status: 201)]
    public function register(RegisterRequest $request, TenantProvisioner $provisioner): JsonResponse
    {
        abort_unless(SaasSettings::registrationOpen(), 403, RegisterRequest::CLOSED_MESSAGE);

        ['owner' => $owner] = $provisioner->provision(
            $request->string('shop_name')->trim()->value(),
            $request->safe()->only(['name', 'username', 'email', 'phone', 'password']),
        );

        return $this->created($this->issueToken($owner, $request->string('device_name')));
    }

    /**
     * Profil akun yang sedang login.
     *
     * Termasuk peran, izin efektif, dan fitur yang aktif, dipakai untuk menampilkan atau
     * menyembunyikan menu di aplikasi.
     */
    public function me(Request $request): CurrentUserResource
    {
        return new CurrentUserResource($request->user());
    }

    /**
     * Logout (cabut token perangkat ini).
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        activity('auth')
            ->causedBy($request->user())
            ->performedOn($request->user())
            ->event('logout')
            ->log('Logout dari aplikasi mobile.');

        return response()->noContent();
    }

    /**
     * Logout dari semua perangkat.
     */
    public function logoutAll(Request $request): Response
    {
        $request->user()->tokens()->delete();

        return response()->noContent();
    }

    /**
     * Konfigurasi autentikasi (Google, Apple) yang aktif di backend.
     */
    public function config(SocialAuthService $socialAuth): JsonResponse
    {
        return response()->json([
            'data' => $socialAuth->authConfig(),
        ]);
    }

    /**
     * Login atau register dengan Google ID Token.
     */
    public function google(Request $request, SocialAuthService $socialAuth): TokenResource
    {
        $request->validate([
            'id_token' => ['required', 'string'],
            'shop_name' => ['nullable', 'string', 'max:100'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $payload = $socialAuth->verifyGoogleToken($request->string('id_token'));
        $user = $socialAuth->findOrCreateUser('google', $payload, $request->string('shop_name')->trim()->value());

        return $this->issueToken($user, $request->string('device_name', 'Google Client'));
    }

    /**
     * Login atau register dengan Apple Identity Token.
     */
    public function apple(Request $request, SocialAuthService $socialAuth): TokenResource
    {
        $request->validate([
            'identity_token' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:100'],
            'shop_name' => ['nullable', 'string', 'max:100'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $payload = $socialAuth->verifyAppleToken($request->string('identity_token'));
        if ($request->filled('name')) {
            $payload['name'] = $request->string('name')->trim()->value();
        }
        $user = $socialAuth->findOrCreateUser('apple', $payload, $request->string('shop_name')->trim()->value());

        return $this->issueToken($user, $request->string('device_name', 'Apple Client'));
    }

    /**
     * Hapus akun pengguna (Apple App Store Guideline 5.1.1(v)).
     */
    public function deleteAccount(Request $request, SocialAuthService $socialAuth): Response
    {
        $user = $request->user();
        $socialAuth->deleteAccount($user);

        return response()->noContent();
    }

    private function issueToken(User $user, string $deviceName): TokenResource
    {
        // Login terjadi di route tamu, jadi tenant belum aktif; peran di respons dibaca per tenant.
        app(CurrentTenant::class)->set($user->tenant_id);

        event(new Login('sanctum', $user, false));

        return new TokenResource([
            'token' => $user->createToken($deviceName)->plainTextToken,
            'user' => $user,
        ]);
    }

    /**
     * WhatsAppOtpService keys its errors to the web form field; the API field is "login".
     *
     * @template T
     *
     * @param  \Closure(): T  $action
     * @return T
     */
    private function withLoginErrorKey(\Closure $action): mixed
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['login' => collect($e->errors())->flatten()->first()]);
        }
    }
}
