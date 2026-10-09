<?php

namespace App\Notifications;

use App\Models\StockCount;
use App\Support\NumberFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Satu ringkasan per opname, termasuk jumlah barang yang jadi menipis, menggantikan peringatan stok per produk.
 */
class StockCountPostedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public StockCount $count, public bool $alert = false) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array{icon: string, color: string, message: string, url: string, stock_count_id: int}
     */
    public function toArray(object $notifiable): array
    {
        $summary = $this->count->summary ?? [];
        $message = ($this->alert ? 'Perhatian, selisih kurang besar. ' : '')."Opname {$this->count->number} selesai: ".($summary['changed'] ?? 0).' barang disesuaikan';

        if (($summary['shortage_value'] ?? 0) > 0) {
            $message .= ', kurang '.NumberFormatter::currency((int) $summary['shortage_value']);
        }

        if (($summary['low_stock'] ?? 0) > 0) {
            $message .= ", {$summary['low_stock']} barang kini menipis";
        }

        return [
            'icon' => 'clipboard-check',
            'color' => $this->alert ? 'rose' : (($summary['shortage_value'] ?? 0) > 0 ? 'amber' : 'emerald'),
            'message' => $message.'.',
            'url' => route('inventory.opname.show', $this->count),
            'stock_count_id' => $this->count->id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
