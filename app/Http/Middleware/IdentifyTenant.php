<?php

namespace App\Http\Middleware;

use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mengaktifkan tenant milik user yang login. Dipasang di grup web (session) dan api (Sanctum);
 * guard dibaca langsung di sini karena middleware grup jalan sebelum middleware auth di route.
 */
class IdentifyTenant
{
    public function handle(Request $request, Closure $next, string $guard = 'web'): Response
    {
        $tenantId = Auth::guard($guard)->user()?->getAttribute('tenant_id');

        if ($tenantId !== null) {
            app(CurrentTenant::class)->set((int) $tenantId);
        }

        return $next($request);
    }
}
