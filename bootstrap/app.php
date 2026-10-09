<?php

use App\Http\Middleware\EnsureApiFeatureEnabled;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureProTenant;
use App\Http\Middleware\EnsureStoreOnboarded;
use App\Http\Middleware\EnsureTenantAccess;
use App\Http\Middleware\IdentifyOutlet;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Pos\PosException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Production sits behind Cloudflare; without this, redirects are built as http:// and
        // wire:navigate (e.g. after logout) hangs because the browser blocks the mixed-content hop.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->append(SecurityHeaders::class);
        $middleware->encryptCookies(except: [
            'theme',
        ]);
        $middleware->alias([
            'feature' => EnsureApiFeatureEnabled::class,
            'pro' => EnsureProTenant::class,
        ]);
        $middleware->web(append: [
            IdentifyTenant::class,
            IdentifyOutlet::class,
            EnsureTenantAccess::class,
            EnsureStoreOnboarded::class,
            EnsureFeatureEnabled::class,
        ]);
        $middleware->api(append: [
            IdentifyTenant::class.':sanctum',
            IdentifyOutlet::class.':sanctum',
            EnsureTenantAccess::class.':sanctum',
        ]);
        // Route model binding must run with the tenant scope active; appended group middleware
        // otherwise runs after SubstituteBindings and {sale}/{product} resolve across shops.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: IdentifyTenant::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: IdentifyOutlet::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Route model binding failures would otherwise expose "No query results for model [App\Models\...]".
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') && $e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json(['message' => __('Data tidak ditemukan.')], 404);
            }
        });
        // `reason` lets the app react without parsing text (e.g. price_changed carries the new prices in `context`).
        $exceptions->render(function (PosException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['message' => [$e->getMessage()]],
                    'reason' => $e->reason,
                    'context' => (object) $e->context,
                ], $e->reason === 'outlet_locked' ? 423 : 422);
            }
        });
    })->create();
