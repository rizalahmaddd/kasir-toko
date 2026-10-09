<?php

namespace App\Notifications;

use App\Models\StockCount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class StockCountSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public StockCount $count, public string $counterName) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array{icon: string, color: string, message: string, url: string, stock_count_id: int}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'clipboard-check',
            'color' => 'sky',
            'message' => "{$this->counterName} selesai menghitung di opname {$this->count->number}. Silakan diperiksa.",
            'url' => route('inventory.opname.show', $this->count),
            'stock_count_id' => $this->count->id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
