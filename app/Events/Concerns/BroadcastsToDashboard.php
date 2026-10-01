<?php

namespace App\Events\Concerns;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Semua event realtime siaran ke satu channel privat "dashboard"; yang beda cuma nama event dan
 * payload-nya. Kelas pemakai WAJIB juga implements ShouldRescue (selain ShouldBroadcastNow) supaya
 * Reverb yang mati cukup dicatat di log, bukan menggagalkan transaksi yang mendispatch event ini.
 */
trait BroadcastsToDashboard
{
    public function broadcastOn(): array
    {
        return [new PrivateChannel('dashboard')];
    }
}
