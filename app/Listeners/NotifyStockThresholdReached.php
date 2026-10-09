<?php

namespace App\Listeners;

use App\Events\StockThresholdReached;
use App\Models\Outlet;
use App\Models\User;
use App\Notifications\ProductStockAlertNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Mengirim notifikasi peringatan stok kritis ke pengelola stok dan inventaris toko.
 */
class NotifyStockThresholdReached
{
    public function handle(StockThresholdReached $event): void
    {
        $product = $event->product;
        $tenantId = $product->tenant_id;

        $outlet = $event->outletId ? Outlet::query()->withoutGlobalScopes()->find($event->outletId) : null;
        $isMultiOutlet = $outlet && Outlet::query()->withoutGlobalScopes()->where('tenant_id', $outlet->tenant_id)->count() > 1;

        $recipients = User::query()
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($isMultiOutlet, fn ($query) => $query->where(fn ($query) => $query
                ->where('all_outlets', true)
                ->orWhereHas('outlets', fn ($query) => $query->whereKey($outlet->id))
                ->orWhereHas('roles', fn ($query) => $query->where('name', 'superadmin'))))
            ->where(fn ($query) => $query
                ->whereHas('roles.permissions', fn ($query) => $query->where('name', 'inventory.manage'))
                ->orWhereHas('permissions', fn ($query) => $query->where('name', 'inventory.manage'))
                ->orWhereHas('roles', fn ($query) => $query->where('name', 'superadmin')))
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ProductStockAlertNotification(
                $product,
                $event->currentStock,
                $event->minStock,
                $event->isOutOfStock,
                $isMultiOutlet ? $outlet->name : null,
            ));
        }
    }
}
