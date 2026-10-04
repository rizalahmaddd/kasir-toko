<?php

namespace App\Http\Middleware;

use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(CurrentTenant::class)->get() ?? $request->user()?->tenant;

        if ($tenant && ! $tenant->isPro()) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Fitur ini khusus untuk paket Pro. Silakan upgrade paket toko Anda.',
                    'upgrade_required' => true,
                ], 403);
            }

            return redirect()->route('settings.subscription')
                ->with('pro_required', true);
        }

        return $next($request);
    }
}
