<?php

namespace App\Notifications;

use App\Models\Product;
use App\Support\NumberFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi peringatan stok produk menipis atau habis.
 */
class ProductStockAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Product $product,
        public float $currentStock,
        public float $minStock,
        public bool $isOutOfStock = false,
        public ?string $outletName = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array{icon: string, color: string, message: string, url: string, product_id: int}
     */
    public function toArray(object $notifiable): array
    {
        $stockFormatted = NumberFormatter::quantity($this->currentStock);
        $minFormatted = NumberFormatter::quantity($this->minStock);
        $unit = $this->product->unit ?: 'pcs';
        $where = $this->outletName ? " di {$this->outletName}" : '';

        if ($this->isOutOfStock || $this->currentStock <= 0) {
            $message = "Stok habis: {$this->product->name} sudah kosong{$where} ({$stockFormatted} {$unit})!";
            $color = 'rose';
            $icon = 'package-x';
        } else {
            $message = "Stok menipis: {$this->product->name} tersisa {$stockFormatted} {$unit}{$where} (batas minimum {$minFormatted} {$unit}).";
            $color = 'amber';
            $icon = 'alert-triangle';
        }

        return [
            'icon' => $icon,
            'color' => $color,
            'message' => $message,
            'url' => route('inventory.stock'),
            'product_id' => $this->product->id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
