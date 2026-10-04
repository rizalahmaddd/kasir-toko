<?php

namespace App\Listeners;

use App\Events\SubscriptionPaymentCompleted;
use App\Notifications\SubscriptionPaidNotification;

/**
 * Mengirim notifikasi pelunasan paket langganan ke pemilik toko.
 */
class NotifySubscriptionPaid
{
    public function handle(SubscriptionPaymentCompleted $event): void
    {
        $owner = $event->tenant->owner();

        if ($owner) {
            $owner->notify(new SubscriptionPaidNotification($event->invoice, $event->tenant, $event->newEndsAt));
        }
    }
}
