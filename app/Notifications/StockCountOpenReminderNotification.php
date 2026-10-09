<?php

namespace App\Notifications;

use App\Models\StockCount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class StockCountOpenReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public StockCount $count) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array{icon: string, color: string, message: string, url: string, stock_count_id: int}
     */
    public function toArray(object $notifiable): array
    {
        $days = (int) $this->count->started_at->diffInDays(now());
        $hold = $this->count->hold_adjustments ? ' Stok masuk/keluar barangnya masih ditahan.' : '';

        return [
            'icon' => 'clipboard-check',
            'color' => 'amber',
            'message' => "Opname {$this->count->number} sudah terbuka {$days} hari. Selesaikan atau batalkan supaya hasil hitungnya tidak basi.{$hold}",
            'url' => route('inventory.opname.show', $this->count),
            'stock_count_id' => $this->count->id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
