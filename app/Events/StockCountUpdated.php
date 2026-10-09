<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToOutlet;
use App\Models\StockCount;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

class StockCountUpdated implements ShouldBroadcastNow, ShouldRescue
{
    use BroadcastsToOutlet, Dispatchable;

    public function __construct(public StockCount $count) {}

    protected function broadcastOutletId(): ?int
    {
        return $this->count->outlet_id;
    }

    public function broadcastAs(): string
    {
        return 'stock-count.updated';
    }

    /**
     * @return array{id: int, number: string, status: string}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->count->id,
            'number' => $this->count->number,
            'status' => $this->count->status->value,
        ];
    }
}
