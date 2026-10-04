<?php

namespace App\Notifications;

use App\Models\Sale;
use App\Models\User;
use App\Support\NumberFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi saat transaksi penjualan dibatalkan (void).
 */
class SaleVoidedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Sale $sale,
        public User $voidedBy,
        public string $reason
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array{icon: string, color: string, message: string, url: string, sale_id: int}
     */
    public function toArray(object $notifiable): array
    {
        $totalFormatted = NumberFormatter::currency((int) $this->sale->total);
        $reasonSnippet = mb_strimwidth($this->reason, 0, 40, '...');

        return [
            'icon' => 'receipt-x',
            'color' => 'rose',
            'message' => "Transaksi {$this->sale->number} ({$totalFormatted}) dibatalkan oleh {$this->voidedBy->name}. Alasan: \"{$reasonSnippet}\".",
            'url' => route('sales.show', $this->sale),
            'sale_id' => $this->sale->id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
