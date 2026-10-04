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
 * Notifikasi saat ada transaksi kasbon (piutang) baru atau pelunasan kasbon pelanggan.
 */
class CustomerReceivableNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Sale $sale,
        public int $amount,
        public string $type = 'created', // 'created' | 'collected'
        public ?User $actor = null
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
        $customerName = $this->sale->customer?->name ?? 'Pelanggan Umum';
        $amountFormatted = NumberFormatter::currency($this->amount);

        if ($this->type === 'collected') {
            $actorName = $this->actor?->name ? " oleh {$this->actor->name}" : '';
            $message = "Pelunasan piutang: {$customerName} membayar {$amountFormatted} untuk transaksi {$this->sale->number}{$actorName}.";
            $color = 'emerald';
            $icon = 'hand-coins';
        } else {
            $message = "Kasbon baru: {$customerName} memiliki piutang {$amountFormatted} pada transaksi {$this->sale->number}.";
            $color = 'amber';
            $icon = 'receipt';
        }

        return [
            'icon' => $icon,
            'color' => $color,
            'message' => $message,
            'url' => route('receivables.index'),
            'sale_id' => $this->sale->id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
