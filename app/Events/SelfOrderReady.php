<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToOutlet;
use App\Models\SelfOrder;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Semua tiket dapur pesanan QR ini selesai: kasir dan pelayan diberi tahu untuk mengantar atau memanggil pelanggan.
 */
class SelfOrderReady implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use BroadcastsToOutlet, Dispatchable;

    public function __construct(public SelfOrder $order) {}

    protected function broadcastOutletId(): ?int
    {
        return $this->order->outlet_id;
    }

    protected function alsoToDashboard(): bool
    {
        return false;
    }

    public function broadcastAs(): string
    {
        return 'self-order.ready';
    }

    /**
     * @return array{id: int, label: string, customer: string}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->order->id, 'label' => $this->order->label(), 'customer' => $this->order->customer_name];
    }
}
