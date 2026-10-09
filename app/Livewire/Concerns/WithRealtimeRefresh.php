<?php

namespace App\Livewire\Concerns;

use App\Support\CurrentOutlet;
use App\Support\CurrentTenant;

/**
 * Langganan event realtime tanpa menulis ulang boilerplate echo-private di tiap komponen.
 * Konsumen cukup mengembalikan nama event (broadcastAs()) yang relevan dari realtimeEvents();
 * semuanya siaran ke channel privat milik toko aktif (App\Events\Concerns\BroadcastsToDashboard).
 */
trait WithRealtimeRefresh
{
    public function getListeners(): array
    {
        $channel = app(CurrentTenant::class)->dashboardChannel();

        if ($channel === null) {
            return [];
        }

        $outletChannel = app(CurrentOutlet::class)->channel();

        return collect($this->realtimeEvents())
            ->mapWithKeys(fn (string $event) => ["echo-private:{$channel},.{$event}" => '$refresh'])
            ->merge($outletChannel === null ? [] : collect($this->realtimeOutletEvents())->mapWithKeys(fn (string $event) => ["echo-private:{$outletChannel},.{$event}" => '$refresh']))
            ->all();
    }

    /**
     * Event yang hanya relevan untuk outlet yang sedang dipakai (channel outlet), mis. penjualan di layar stok.
     *
     * @return array<int, string>
     */
    protected function realtimeOutletEvents(): array
    {
        return [];
    }

    /**
     * Nama-nama event (hasil broadcastAs()) yang relevan untuk komponen ini. Override di kelas
     * pemakai; sengaja method, bukan property, supaya default value-nya tidak bentrok dengan
     * PHP "incompatible trait property" saat komponen mendeklarasikan nilai default sendiri.
     *
     * @return array<int, string>
     */
    protected function realtimeEvents(): array
    {
        return [];
    }
}
