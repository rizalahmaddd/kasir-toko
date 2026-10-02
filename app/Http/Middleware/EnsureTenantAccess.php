<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menutup aplikasi untuk toko yang di-suspend atau masa aktifnya habis, dan menjaga admin
 * platform (tanpa tenant) tetap di panel Platform. Tanpa tenant, global scope tidak memfilter
 * apa pun, jadi halaman toko WAJIB tertutup untuk akun seperti itu.
 */
class EnsureTenantAccess
{
    public const MESSAGES = [
        'tenant_suspended' => 'Toko ini sedang dinonaktifkan. Hubungi admin layanan.',
        'trial_expired' => 'Masa uji coba toko ini sudah berakhir. Hubungi admin layanan untuk berlangganan.',
        'subscription_expired' => 'Langganan toko ini sudah berakhir. Hubungi admin layanan untuk memperpanjang.',
    ];

    /**
     * Endpoint Livewire bernama "default.livewire.update"; aksinya diperiksa ulang lewat persistent
     * middleware dengan route halaman aslinya, jadi endpoint-nya sendiri dibiarkan lewat.
     */
    private const ALWAYS_ALLOWED = ['subscription.inactive', 'profile', 'verification.*', 'password.confirm', 'branding.logo', '*livewire.*'];

    private const API_ALLOWED = ['api.v1.auth.me', 'api.v1.auth.logout', 'api.v1.auth.logout-all'];

    public function handle(Request $request, Closure $next, string $guard = 'web'): Response
    {
        $user = Auth::guard($guard)->user();

        if (! $user instanceof User || $request->routeIs(...self::ALWAYS_ALLOWED, ...self::API_ALLOWED)) {
            return $next($request);
        }

        $isApi = $guard !== 'web';

        if ($user->tenant_id === null) {
            if ($user->is_platform_admin && $request->routeIs('platform.*')) {
                return $next($request);
            }

            abort_if($isApi || ! $user->is_platform_admin || $this->isLivewire($request), 403, __('Akun ini tidak terhubung ke toko mana pun.'));

            return redirect()->route('platform.dashboard');
        }

        $reason = app(CurrentTenant::class)->get()?->blockedReason();

        if ($reason === null) {
            return $next($request);
        }

        if ($isApi) {
            return response()->json(['message' => self::MESSAGES[$reason], 'reason' => $reason], 402);
        }

        abort_if($this->isLivewire($request), 402, self::MESSAGES[$reason]);

        return redirect()->route('subscription.inactive');
    }

    private function isLivewire(Request $request): bool
    {
        return $request->hasHeader('X-Livewire');
    }
}
