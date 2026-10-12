<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToOutlet;
use App\Models\SelfOrder;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Kotak masuk pesanan QR di kasir outlet perlu dimuat ulang (pesanan baru, diterima, dibayar, dibatalkan).
 */
class SelfOrderChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use BroadcastsToOutlet, Dispatchable;

    public function __construct(public SelfOrder $order, public bool $isNew = false) {}

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
        return 'self-order.changed';
    }

    /**
     * @return array{id: int, label: string, status: string, new: bool}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->order->id, 'label' => $this->order->label(), 'status' => $this->order->status, 'new' => $this->isNew];
    }
}
