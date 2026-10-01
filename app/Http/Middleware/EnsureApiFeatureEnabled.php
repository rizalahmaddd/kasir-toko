<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API counterpart of EnsureFeatureEnabled. Web routes are matched to features by route name,
 * API routes name their feature keys explicitly: ->middleware('feature:master-data.customers').
 */
class EnsureApiFeatureEnabled
{
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        foreach ($features as $feature) {
            abort_unless(Features::enabled($feature), 403, __('Fitur ini sedang dinonaktifkan.'));
        }

        return $next($request);
    }
}
