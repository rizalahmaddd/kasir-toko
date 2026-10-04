<?php

namespace App\Listeners;

use App\Events\SubscriptionBonusGranted;
use App\Notifications\SubscriptionBonusNotification;

/**
 * Mengirim notifikasi penambahan masa aktif atau bonus paket Pro ke pemilik toko.
 */
class NotifySubscriptionBonus
{
    public function handle(SubscriptionBonusGranted $event): void
    {
        $owner = $event->tenant->owner();

        if ($owner) {
            $owner->notify(new SubscriptionBonusNotification($event->tenant, $event->accessEndsAt, $event->note, $event->addedDays));
        }
    }
}
