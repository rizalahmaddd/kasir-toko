<?php

namespace App\Services;

use App\Models\User;
use App\Support\Branding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WhatsAppOtpService
{
    public function __construct(
        protected FonnteService $fonnteService
    ) {}

    /**
     * Cari user berdasarkan username, email, atau nomor HP.
     */
    public function findUser(string $identifier): ?User
    {
        $input = trim($identifier);

        if (blank($input)) {
            return null;
        }

        return match (true) {
            str_contains($input, '@') => User::where('email', Str::lower($input))->first(),
            (bool) preg_match('/^\+?[\d\s\-()]{8,}$/', $input) => User::where('phone', User::normalizePhone($input))->first(),
            default => User::where('username', Str::lower($input))->first(),
        };
    }

    /**
     * Cek apakah token WhatsApp (Fonnte) sudah dikonfigurasi.
     */
    public static function isConfigured(): bool
    {
        return filled(config('services.fonnte.token'));
    }

    /**
     * Kirim kode OTP WhatsApp ke pengguna. Respons untuk identitas yang tidak terdaftar (atau
     * akun tanpa nomor HP) dibuat sama persis dengan yang terdaftar, termasuk cooldown dan sisa
     * percobaan, supaya form ini tidak bisa dipakai menebak akun mana yang ada.
     *
     * @return array{challenge: string, masked_phone: string, cooldown_seconds: int}
     *
     * @throws ValidationException
     */
    public function sendOtp(string $identifier): array
    {
        if (! static::isConfigured() && ! app()->environment('testing')) {
            throw ValidationException::withMessages([
                'waIdentifier' => 'Layanan WhatsApp OTP belum dikonfigurasi.',
            ]);
        }

        $user = $this->findUser($identifier);
        $canReceive = $user !== null && filled($user->phone);
        $challenge = $canReceive ? (string) $user->id : 'x'.hash('sha256', Str::lower(trim($identifier)));

        $cooldownKey = "wa_otp_cooldown_{$challenge}";
        if (Cache::has($cooldownKey)) {
            $expiresAt = (int) Cache::get($cooldownKey);
            $remaining = max(1, $expiresAt - now()->timestamp);

            throw ValidationException::withMessages([
                'waIdentifier' => "Mohon tunggu {$remaining} detik sebelum meminta kode OTP kembali.",
            ]);
        }

        // Akun palsu menyimpan hash string acak, jadi tidak ada kode 6 digit yang bisa cocok.
        $otp = match (true) {
            ! $canReceive => Str::random(32),
            app()->environment('testing') => '123456',
            default => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
        };

        Cache::put("wa_otp_{$challenge}", ['hash' => Hash::make($otp)], now()->addMinutes(5));
        Cache::forget("wa_otp_fails_{$challenge}");
        Cache::put($cooldownKey, now()->addSeconds(60)->timestamp, now()->addSeconds(60));

        if ($canReceive) {
            $appName = Branding::appName();
            $message = "*{$appName}*\n".
                "Kode verifikasi (OTP) login Anda adalah: *{$otp}*\n\n".
                'Kode ini berlaku selama 5 menit. Demi keamanan, JANGAN bagikan kode ini kepada siapa pun.';

            $sendResult = $this->fonnteService->send($user->phone, $message);

            if (! ($sendResult['status'] ?? false)) {
                Cache::forget("wa_otp_{$challenge}");
                Cache::forget($cooldownKey);

                throw ValidationException::withMessages([
                    'waIdentifier' => $sendResult['message'] ?? 'Gagal mengirim kode OTP ke WhatsApp. Pastikan perangkat Fonnte terhubung.',
                ]);
            }
        }

        return [
            'challenge' => $challenge,
            'masked_phone' => self::maskIdentifier($identifier),
            'cooldown_seconds' => 60,
        ];
    }

    /**
     * Verifikasi kode OTP dan kembalikan User yang valid.
     *
     * @throws ValidationException
     */
    public function verifyOtp(int|string $challenge, string $otp): User
    {
        $cacheKey = "wa_otp_{$challenge}";
        $failsKey = "wa_otp_fails_{$challenge}";

        $otpData = Cache::get($cacheKey);

        if (! is_array($otpData) || empty($otpData['hash'])) {
            throw ValidationException::withMessages([
                'otp' => 'Kode OTP telah kedaluwarsa atau belum diminta. Silakan minta kode baru.',
            ]);
        }

        $fails = (int) Cache::get($failsKey, 0);

        if ($fails >= 5) {
            Cache::forget($cacheKey);
            Cache::forget($failsKey);

            throw ValidationException::withMessages([
                'otp' => 'Terlalu banyak percobaan salah. Kode OTP ini dibatalkan, silakan minta kode baru.',
            ]);
        }

        if (! Hash::check(trim($otp), $otpData['hash'])) {
            $newFails = $fails + 1;
            Cache::put($failsKey, $newFails, now()->addMinutes(5));
            $sisa = max(0, 5 - $newFails);

            throw ValidationException::withMessages([
                'otp' => "Kode OTP salah. Sisa kesempatan: {$sisa} kali.",
            ]);
        }

        Cache::forget($cacheKey);
        Cache::forget($failsKey);
        Cache::forget("wa_otp_cooldown_{$challenge}");

        return User::findOrFail((int) $challenge);
    }

    /**
     * Cek sisa detik cooldown pengiriman ulang.
     */
    public function getCooldownRemaining(int|string $challenge): int
    {
        $cooldownKey = "wa_otp_cooldown_{$challenge}";

        if (! Cache::has($cooldownKey)) {
            return 0;
        }

        return max(0, (int) Cache::get($cooldownKey) - now()->timestamp);
    }

    /**
     * Yang ditampilkan sebagai tujuan OTP. Diambil dari isian pengguna sendiri, bukan dari data
     * akun, supaya tidak membocorkan nomor milik username/email yang dicoba.
     */
    public static function maskIdentifier(string $identifier): string
    {
        $input = trim($identifier);

        return preg_match('/^\+?[\d\s\-()]{8,}$/', $input) ? self::maskPhone(User::normalizePhone($input)) : 'nomor WhatsApp akun ini';
    }

    /**
     * Sensor nomor HP untuk tampilan aman di antarmuka (mis. 0812****7890).
     */
    public static function maskPhone(string $phone): string
    {
        $clean = preg_replace('/\D/', '', $phone);
        $len = strlen($clean);

        if ($len <= 6) {
            return $clean;
        }

        $prefix = substr($clean, 0, 4);
        $suffix = substr($clean, -4);

        return $prefix.' •••• '.$suffix;
    }
}
