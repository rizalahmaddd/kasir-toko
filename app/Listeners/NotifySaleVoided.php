<?php

namespace App\Listeners;

use App\Events\SaleVoided;
use App\Models\User;
use App\Notifications\SaleVoidedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Mengirim notifikasi pembatalan transaksi (void) ke pemilik dan manajer toko.
 */
class NotifySaleVoided
{
    public function handle(SaleVoided $event): void
    {
        $sale = $event->sale;
        $user = $event->user;

        $recipients = User::query()
            ->where('tenant_id', $sale->tenant_id)
            ->where(fn ($query) => $query
                ->whereHas('roles.permissions', fn ($query) => $query->whereIn('name', ['sales.view', 'pos.void']))
                ->orWhereHas('permissions', fn ($query) => $query->whereIn('name', ['sales.view', 'pos.void']))
                ->orWhereHas('roles', fn ($query) => $query->where('name', 'superadmin')))
            ->when($user->id, fn ($query) => $query->whereKeyNot($user->id))
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new SaleVoidedNotification($sale, $user, $event->reason));
        }
    }
}
