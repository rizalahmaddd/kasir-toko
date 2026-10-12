<?php

namespace App\Http\Middleware;

use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\SelfOrder;
use App\Support\Branding;
use App\Support\CurrentOutlet;
use App\Support\CurrentTenant;
use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman pesanan QR dibuka tanpa login. Tanpa tenant aktif, TenantScope tidak memfilter apa pun, jadi
 * WAJIB: tenant dan outlet diaktifkan dari token di URL sebelum controller menyentuh database. Sesi login
 * yang kebetulan ada di browser itu diabaikan; konteksnya selalu milik token.
 */
class IdentifySelfOrderContext
{
    public const OUTLET = 'self_order.outlet';

    public const TABLE = 'self_order.table';

    public const ORDER = 'self_order.order';

    public function handle(Request $request, Closure $next): Response
    {
        [$outlet, $table, $order] = $this->resolve($request);

        abort_if($outlet === null, 404);

        // CurrentOutlet dibuat ulang supaya pembatasan outlet milik user yang sedang login tidak ikut terbawa.
        app()->forgetScopedInstances();
        app(CurrentTenant::class)->set((int) $outlet->tenant_id);
        app(CurrentOutlet::class)->set($outlet->id);

        $tenant = $outlet->tenant;

        if (! $tenant || $tenant->blockedReason() !== null || ! $tenant->isPro() || ! $outlet->isOperational() || ! Features::enabledAt('business.self-order', $outlet->id)) {
            return $this->unavailable($request);
        }

        $request->attributes->set(self::OUTLET, $outlet);
        $request->attributes->set(self::TABLE, $table);
        $request->attributes->set(self::ORDER, $order);

        return $next($request);
    }

    /**
     * @return array{0: ?Outlet, 1: ?DiningTable, 2: ?SelfOrder}
     */
    private function resolve(Request $request): array
    {
        $route = $request->route();
        $find = fn (?int $id): ?Outlet => $id === null ? null : Outlet::query()->withoutGlobalScopes()->whereKey($id)->first();

        if ($sessionToken = $route?->parameter('sessionToken')) {
            $order = strlen((string) $sessionToken) === 64 ? SelfOrder::query()->withoutGlobalScopes()->where('session_token', $sessionToken)->first() : null;

            return [$find($order?->outlet_id), null, $order];
        }

        $token = (string) ($route?->parameter('tableToken') ?? $route?->parameter('outletToken') ?? $route?->parameter('token'));

        if (strlen($token) !== 40) {
            return [null, null, null];
        }

        if (! $route->hasParameter('outletToken')) {
            $table = DiningTable::query()->withoutGlobalScopes()->where('token', $token)->where('is_active', true)->first();

            if ($table) {
                return [$find($table->outlet_id), $table, null];
            }
        }

        if (! $route->hasParameter('tableToken')) {
            return [Outlet::query()->withoutGlobalScopes()->where('self_order_token', $token)->first(), null, null];
        }

        return [null, null, null];
    }

    private function unavailable(Request $request): Response
    {
        $message = 'Pemesanan lewat QR sedang tidak tersedia di tempat ini. Silakan pesan langsung ke kasir.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'reason' => 'unavailable'], 404);
        }

        return response()->view('self-order.unavailable', ['message' => $message, 'storeName' => Branding::companyName()], 404);
    }
}
