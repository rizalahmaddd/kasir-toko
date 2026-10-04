<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToDashboard;
use App\Models\Sale;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

class SaleRecorded implements ShouldBroadcastNow, ShouldRescue
{
    use BroadcastsToDashboard, Dispatchable;

    public function __construct(public Sale $sale) {}

    public function broadcastAs(): string
    {
        return 'sale.recorded';
    }

    /**
     * @return array{id: int, number: string}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->sale->id,
            'number' => $this->sale->number,
        ];
    }
}
