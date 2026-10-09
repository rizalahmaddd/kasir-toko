<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Ringkasan harian batch yang sudah atau hampir kedaluwarsa di satu toko (paket Pro).
 */
class ProductsExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $tenantId,
        public int $expiredCount,
        public int $soonCount,
        public int $days,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array{icon: string, color: string, message: string, url: string, tenant_id: int}
     */
    public function toArray(object $notifiable): array
    {
        $parts = array_filter([
            $this->expiredCount > 0 ? "{$this->expiredCount} batch sudah kedaluwarsa" : null,
            $this->soonCount > 0 ? "{$this->soonCount} batch kedaluwarsa dalam {$this->days} hari" : null,
        ]);

        return [
            'icon' => 'calendar-x-2',
            'color' => $this->expiredCount > 0 ? 'rose' : 'amber',
            'message' => ucfirst(implode(' dan ', $parts)).'. Jual lebih dulu, kembalikan ke pemasok, atau catat sebagai stok keluar.',
            'url' => route('inventory.expiry', ['window' => $this->expiredCount > 0 ? 'expired' : (string) $this->days]),
            'tenant_id' => $this->tenantId,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
