<?php

namespace App\Events;

use App\Models\SelfOrder;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Sinyal ke HP pelanggan bahwa status pesanannya berubah. Channel publik karena pelanggan tidak login;
 * nama channel memuat session_token pesanan yang hanya dimiliki pemesannya.
 */
class SelfOrderStatusUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    public function __construct(public SelfOrder $order) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('self-order.'.$this->order->session_token)];
    }

    public function broadcastAs(): string
    {
        return 'self-order.updated';
    }

    /**
     * @return array{status: string, payment_status: string}
     */
    public function broadcastWith(): array
    {
        return ['status' => $this->order->status, 'payment_status' => $this->order->payment_status];
    }
}
