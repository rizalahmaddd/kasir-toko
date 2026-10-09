<?php

namespace App\Http\Middleware;

use App\Models\CashShift;
use App\Models\User;
use App\Support\CurrentOutlet;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menentukan outlet aktif request: header X-Outlet-Id (API, harus valid), session (web), lalu
 * outlet shift yang sedang terbuka, outlet terakhir dipakai, dan outlet utama. Berjalan setelah
 * IdentifyTenant dan sebelum route model binding supaya pembatasan outlet ikut berlaku di binding.
 */
class IdentifyOutlet
{
    public const SESSION_KEY = 'current_outlet_id';

    public const HEADER = 'X-Outlet-Id';

    /**
     * Route yang tetap terbuka walau header outlet salah atau user tidak punya outlet, supaya klien
     * bisa memuat ulang daftar outletnya, keluar, atau mengelola langganan.
     */
    private const ALWAYS_ALLOWED = ['subscription.inactive', 'settings.subscription*', 'platform.impersonate.leave', 'profile', 'verification.*', 'password.confirm', 'branding.logo', '*livewire.*', 'logout', 'api.v1.auth.*', 'api.v1.outlets.index'];

    public function handle(Request $request, Closure $next, string $guard = 'web'): Response
    {
        $user = Auth::guard($guard)->user();

        if (! $user instanceof User || $user->tenant_id === null) {
            return $next($request);
        }

        $current = app(CurrentOutlet::class);
        $current->loadAccess($user);
        $allowed = $request->routeIs(...self::ALWAYS_ALLOWED);
        $isApi = $guard !== 'web';

        $requested = $this->requestedOutletId($request);

        if ($request->hasHeader(self::HEADER) && ($requested === null || ! $current->canAccess($requested))) {
            if (! $allowed) {
                Log::warning('outlet_forbidden', ['user_id' => $user->id, 'tenant_id' => $user->tenant_id, 'outlet' => $request->header(self::HEADER)]);

                return response()->json(['message' => __('Anda tidak punya akses ke outlet ini.'), 'reason' => 'outlet_forbidden'], 403);
            }

            $requested = null;
        }

        if ($requested !== null && ! $current->canAccess($requested)) {
            $requested = null;
        }

        $outletId = $requested ?? $current->pickDefault([
            CashShift::query()->withoutGlobalScopes()->where('user_id', $user->id)->whereNull('closed_at')->latest('opened_at')->value('outlet_id'),
            $user->default_outlet_id,
        ]);

        if ($outletId === null) {
            if ($allowed) {
                return $next($request);
            }

            $message = __('Anda belum punya akses ke outlet mana pun. Hubungi pemilik toko.');

            if ($isApi || $request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return response()->json(['message' => $message, 'reason' => 'no_outlet_access'], 403);
            }

            abort(403, $message);
        }

        $current->set($outletId);

        return $next($request);
    }

    private function requestedOutletId(Request $request): ?int
    {
        $header = $request->header(self::HEADER);

        if ($header !== null) {
            return ctype_digit((string) $header) ? (int) $header : null;
        }

        $session = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return is_numeric($session) ? (int) $session : null;
    }
}
