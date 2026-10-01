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
     * Kirim kode OTP WhatsApp ke pengguna.
     *
     * @return array{user_id: int, masked_phone: string, cooldown_seconds: int}
     *
     * @throws ValidationException
     */
    public function sendOtp(string $identifier): array
    {
        $user = $this->findUser($identifier);

        if (! $user) {
            throw ValidationException::withMessages([
                'waIdentifier' => 'Akun dengan identitas tersebut tidak ditemukan di sistem.',
            ]);
        }

        if (blank($user->phone)) {
            throw ValidationException::withMessages([
                'waIdentifier' => 'Akun ini belum memiliki nomor WhatsApp terdaftar. Silakan masuk menggunakan password.',
            ]);
        }

        $cooldownKey = "wa_otp_cooldown_{$user->id}";
        if (Cache::has($cooldownKey)) {
            $expiresAt = (int) Cache::get($cooldownKey);
            $remaining = max(1, $expiresAt - now()->timestamp);

            throw ValidationException::withMessages([
                'waIdentifier' => "Mohon tunggu {$remaining} detik sebelum meminta kode OTP kembali.",
            ]);
        }

        // Buat 6-digit angka OTP (di environment testing gunakan '123456')
        $otp = app()->environment('testing')
            ? '123456'
            : str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Simpan hash OTP di cache selama 5 menit
        Cache::put("wa_otp_{$user->id}", [
            'hash' => Hash::make($otp),
            'phone' => $user->phone,
        ], now()->addMinutes(5));
        Cache::forget("wa_otp_fails_{$user->id}");

        // Set cooldown 60 detik
        Cache::put($cooldownKey, now()->addSeconds(60)->timestamp, now()->addSeconds(60));

        // Format pesan WhatsApp
        $appName = Branding::appName();
        $message = "*{$appName}*\n".
            "Kode verifikasi (OTP) login Anda adalah: *{$otp}*\n\n".
            'Kode ini berlaku selama 5 menit. Demi keamanan, JANGAN bagikan kode ini kepada siapa pun.';

        $sendResult = $this->fonnteService->send($user->phone, $message);

        if (! ($sendResult['status'] ?? false)) {
            // Batalkan simpan OTP dan cooldown jika gagal terkirim
            Cache::forget("wa_otp_{$user->id}");
            Cache::forget($cooldownKey);

            throw ValidationException::withMessages([
                'waIdentifier' => $sendResult['message'] ?? 'Gagal mengirim kode OTP ke WhatsApp. Pastikan perangkat Fonnte terhubung.',
            ]);
        }

        return [
            'user_id' => $user->id,
            'masked_phone' => self::maskPhone($user->phone),
            'cooldown_seconds' => 60,
        ];
    }

    /**
     * Verifikasi kode OTP dan kembalikan User yang valid.
     *
     * @throws ValidationException
     */
    public function verifyOtp(int $userId, string $otp): User
    {
        $cacheKey = "wa_otp_{$userId}";
        $failsKey = "wa_otp_fails_{$userId}";

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

        // OTP valid: bersihkan cache
        Cache::forget($cacheKey);
        Cache::forget($failsKey);
        Cache::forget("wa_otp_cooldown_{$userId}");

        return User::findOrFail($userId);
    }

    /**
     * Cek sisa detik cooldown pengiriman ulang.
     */
    public function getCooldownRemaining(int $userId): int
    {
        $cooldownKey = "wa_otp_cooldown_{$userId}";

        if (! Cache::has($cooldownKey)) {
            return 0;
        }

        return max(0, (int) Cache::get($cooldownKey) - now()->timestamp);
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
