<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mengarahkan pemilik toko yang baru mendaftar ke halaman persiapan toko (pilih jenis toko)
 * sampai preset diterapkan atau dilewati. Kasir, peran lain, dan admin platform tidak pernah
 * diarahkan; mereka tetap bisa memakai aplikasi selama pemiliknya belum selesai.
 */
class EnsureStoreOnboarded
{
    private const ALWAYS_ALLOWED = ['onboarding', 'subscription.inactive', 'profile', 'verification.*', 'password.confirm', 'branding.logo', 'api.openapi', '*livewire.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->tenant_id === null || ! $request->isMethod('GET') || $request->expectsJson() || $request->routeIs(...self::ALWAYS_ALLOWED)) {
            return $next($request);
        }

        $tenant = app(CurrentTenant::class)->get();

        if ($tenant === null || $tenant->isOnboarded() || ! $user->isSuperAdmin()) {
            return $next($request);
        }

        return redirect()->route('onboarding');
    }
}
