<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CAPTCHA Cloudflare Turnstile untuk form publik. Mati selama TURNSTILE_SITE_KEY dan
 * TURNSTILE_SECRET_KEY kosong, supaya instalasi lokal tetap bisa mendaftar tanpa akun Cloudflare.
 */
class Turnstile
{
    public const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function enabled(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key'));
    }

    public static function siteKey(): ?string
    {
        return self::enabled() ? config('services.turnstile.site_key') : null;
    }

    /**
     * Gagal tertutup: kalau Cloudflare tidak bisa dihubungi, pendaftaran ditolak.
     */
    public static function verify(?string $token, ?string $ip = null): bool
    {
        if (! self::enabled()) {
            return true;
        }

        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, [
                'secret' => config('services.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('Turnstile tidak bisa dihubungi.', ['error' => $exception->getMessage()]);

            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }
}
