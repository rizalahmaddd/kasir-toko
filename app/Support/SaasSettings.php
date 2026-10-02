<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Pengaturan layanan yang diubah admin platform dari panel. Disimpan sebagai Setting tanpa
 * tenant; nilai bawaannya tetap dari config/saas.php supaya instalasi baru tidak perlu diisi dulu.
 */
class SaasSettings
{
    public const REGISTRATION_OPEN = 'saas.registration_open';

    public const TRIAL_DAYS = 'saas.trial_days';

    public static function registrationOpen(): bool
    {
        return Setting::get(self::REGISTRATION_OPEN, '1') === '1';
    }

    public static function trialDays(): int
    {
        return (int) (Setting::get(self::TRIAL_DAYS) ?? config('saas.trial_days'));
    }
}
