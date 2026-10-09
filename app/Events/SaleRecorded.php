<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToOutlet;
use App\Models\Sale;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

class SaleRecorded implements ShouldBroadcastNow, ShouldRescue
{
    use BroadcastsToOutlet, Dispatchable;

    public function __construct(public Sale $sale) {}

    protected function broadcastOutletId(): ?int
    {
        return $this->sale->outlet_id;
    }

    public function broadcastAs(): string
    {
        return 'sale.recorded';
    }

    /**
     * @return array{id: int, number: string, outlet_id: int|null}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->sale->id,
            'number' => $this->sale->number,
            'outlet_id' => $this->sale->outlet_id,
        ];
    }
}
