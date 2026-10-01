<?php

namespace App\Livewire\Concerns;

/**
 * Langganan event realtime tanpa menulis ulang boilerplate echo-private di tiap komponen.
 * Konsumen cukup mengembalikan nama event (broadcastAs()) yang relevan dari realtimeEvents();
 * semuanya siaran ke channel privat "dashboard" (App\Events\Concerns\BroadcastsToDashboard).
 */
trait WithRealtimeRefresh
{
    public function getListeners(): array
    {
        return collect($this->realtimeEvents())
            ->mapWithKeys(fn (string $event) => ["echo-private:dashboard,.{$event}" => '$refresh'])
            ->all();
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
