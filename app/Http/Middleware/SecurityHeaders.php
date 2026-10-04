<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan untuk semua respons, termasuk Content-Security-Policy. CSP tidak memakai nonce
 * karena wire:navigate menyalin <script> halaman baru beserta nonce-nya sendiri, yang lalu ditolak
 * oleh CSP halaman pertama; karena itu tidak boleh ada <script> inline maupun onclick="...".
 * 'unsafe-eval' wajib selama Alpine bawaan Livewire dipakai (ekspresi x-* dievaluasi lewat Function).
 */
class SecurityHeaders
{
    /**
     * Halaman pihak ketiga yang memuat skrip dari CDN-nya sendiri.
     */
    private const CSP_EXCEPT = ['scalar'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=()');

        if ($request->isSecure() && app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        if (config('app.csp.enabled') && ! $request->routeIs(...self::CSP_EXCEPT)) {
            $header = config('app.csp.report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $response->headers->set($header, $this->contentSecurityPolicy());
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $turnstile = 'https://challenges.cloudflare.com';
        $dev = $this->viteDevServer();
        $devSocket = $dev ? preg_replace('#^http#', 'ws', $dev) : null;

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'unsafe-eval'", $turnstile, $dev],
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com', $dev],
            'font-src' => ["'self'", 'data:', 'https://fonts.gstatic.com', $dev],
            'img-src' => ["'self'", 'data:', 'blob:', 'https://images.unsplash.com', $dev],
            'media-src' => ["'self'", 'blob:'],
            'connect-src' => ["'self'", ...$this->reverbSockets(), $dev, $devSocket],
            'frame-src' => ["'self'", $turnstile],
            'worker-src' => ["'self'", 'blob:'],
            'frame-ancestors' => ["'self'"],
            'form-action' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $name) => $name.' '.implode(' ', array_unique(array_filter($sources))))
            ->implode('; ');
    }

    /**
     * Klien Pusher mencoba wss lalu ws (atau sebaliknya) sebelum menyerah, jadi keduanya diizinkan.
     *
     * @return list<string>
     */
    private function reverbSockets(): array
    {
        $host = config('broadcasting.connections.reverb.options.host');

        if (blank($host)) {
            return [];
        }

        $port = config('broadcasting.connections.reverb.options.port');
        $address = $host.($port ? ":{$port}" : '');

        return ["ws://{$address}", "wss://{$address}"];
    }

    private function viteDevServer(): ?string
    {
        if (! Vite::isRunningHot()) {
            return null;
        }

        $url = trim((string) file_get_contents(Vite::hotFile()));

        return parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).(parse_url($url, PHP_URL_PORT) ? ':'.parse_url($url, PHP_URL_PORT) : '');
    }
}
