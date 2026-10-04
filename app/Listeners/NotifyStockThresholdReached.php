<?php

namespace App\Listeners;

use App\Events\StockThresholdReached;
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

        $recipients = User::query()
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
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
                $event->isOutOfStock
            ));
        }
    }
}
