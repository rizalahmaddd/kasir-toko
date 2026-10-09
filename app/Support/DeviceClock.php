<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Waktu kejadian dari perangkat (HP kasir/penghitung) yang jamnya bisa salah. Selisih jam perangkat
 * dihitung dari device_sent_at (jam perangkat saat mengirim) terhadap jam server, lalu hasilnya dibatasi.
 */
class DeviceClock
{
    public static function correct(mixed $occurredAt, mixed $deviceSentAt, ?CarbonInterface $floor = null): ?Carbon
    {
        $occurred = self::parse($occurredAt);

        if ($occurred === null) {
            return null;
        }

        $sent = self::parse($deviceSentAt);
        $now = now();

        if ($sent !== null) {
            $occurred = $occurred->addSeconds((int) $sent->diffInSeconds($now));
        }

        if ($floor !== null && $occurred->lt($floor)) {
            $occurred = Carbon::parse($floor);
        }

        return $occurred->min($now);
    }

    private static function parse(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
