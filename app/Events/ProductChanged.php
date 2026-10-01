<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToDashboard;
use App\Models\Product;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

class ProductChanged implements ShouldBroadcastNow, ShouldRescue
{
    use BroadcastsToDashboard, Dispatchable;

    public function __construct(public Product $product) {}

    public function broadcastAs(): string
    {
        return 'product.changed';
    }

    /**
     * @return array{id: int, sku: string}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->product->id,
            'sku' => $this->product->sku,
        ];
    }
}
