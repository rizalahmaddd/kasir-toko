<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Dikirim ke pemilik saat batas outlet paket lebih kecil dari jumlah outlet aktif, supaya tahu
 * outlet mana yang kini terkunci dan ke mana memilih ulang.
 */
class OutletsLockedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $lockedNames
     */
    public function __construct(
        public Tenant $tenant,
        public array $lockedNames,
        public int $maxOutlets,
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
        $names = implode(', ', $this->lockedNames);

        return [
            'icon' => 'lock',
            'color' => 'amber',
            'message' => "Paket {$this->tenant->planLabel()} mendukung {$this->maxOutlets} outlet. Outlet {$names} kini terkunci: datanya aman dan bisa dilihat, tetapi tidak bisa bertransaksi. Naikkan paket atau pilih outlet yang beroperasi di Pengaturan > Outlet.",
            'url' => route('settings.outlets'),
            'tenant_id' => $this->tenant->id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
