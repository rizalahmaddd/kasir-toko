<?php

namespace App\Listeners;

use App\Events\CashShiftClosed;
use App\Models\User;
use App\Notifications\CashShiftClosedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Mengirim notifikasi penutupan shift kasir ke manajer dan pemilik toko.
 */
class NotifyCashShiftClosed
{
    public function handle(CashShiftClosed $event): void
    {
        $shift = $event->shift;
        $closer = $event->closer;
        $diff = (int) $shift->cash_difference;

        $recipients = User::query()
            ->where('tenant_id', $shift->tenant_id)
            ->where(fn ($query) => $query
                ->whereHas('roles.permissions', fn ($query) => $query->where('name', 'shifts.manage'))
                ->orWhereHas('permissions', fn ($query) => $query->where('name', 'shifts.manage'))
                ->orWhereHas('roles', fn ($query) => $query->where('name', 'superadmin')))
            ->when($diff === 0, fn ($query) => $query->whereKeyNot($closer->id))
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new CashShiftClosedNotification($shift, $closer));
        }
    }
}
