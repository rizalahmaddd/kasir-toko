<?php

namespace App\Listeners;

use App\Events\TenantRegistered;
use App\Notifications\WelcomeTenantNotification;
use App\Support\SaasSettings;

/**
 * Mengirim notifikasi selamat datang ke pemilik toko saat pendaftaran baru selesai.
 */
class SendWelcomeTenantNotification
{
    public function handle(TenantRegistered $event): void
    {
        $event->owner->notify(new WelcomeTenantNotification($event->tenant, SaasSettings::trialDays()));
    }
}
