<?php

namespace App\Listeners;

use App\Events\CustomerReceivableRecorded;
use App\Models\User;
use App\Notifications\CustomerReceivableNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Mengirim notifikasi kasbon baru atau pelunasan kasbon ke pengelola piutang toko.
 */
class NotifyCustomerReceivable
{
    public function handle(CustomerReceivableRecorded $event): void
    {
        $sale = $event->sale;
        $actorId = $event->actor?->id;

        $recipients = User::query()
            ->where('tenant_id', $sale->tenant_id)
            ->where(fn ($query) => $query
                ->whereHas('roles.permissions', fn ($query) => $query->where('name', 'receivables.manage'))
                ->orWhereHas('permissions', fn ($query) => $query->where('name', 'receivables.manage'))
                ->orWhereHas('roles', fn ($query) => $query->where('name', 'superadmin')))
            ->when($actorId, fn ($query) => $query->whereKeyNot($actorId))
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new CustomerReceivableNotification($sale, $event->amount, $event->type, $event->actor));
        }
    }
}
