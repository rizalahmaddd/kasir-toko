<?php

namespace App\Events\Concerns;

use App\Support\CurrentTenant;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Semua event realtime siaran ke channel privat "tenant.{id}.dashboard" milik toko aktif; yang beda cuma nama event dan
 * payload-nya. Kelas pemakai WAJIB juga implements ShouldRescue (selain ShouldBroadcastNow) supaya
 * Reverb yang mati cukup dicatat di log, bukan menggagalkan transaksi yang mendispatch event ini.
 */
trait BroadcastsToDashboard
{
    public function broadcastOn(): array
    {
        $channel = app(CurrentTenant::class)->dashboardChannel();

        return $channel === null ? [] : [new PrivateChannel($channel)];
    }
}
