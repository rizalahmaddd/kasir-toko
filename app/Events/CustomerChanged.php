<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToDashboard;
use App\Models\Customer;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

class CustomerChanged implements ShouldBroadcastNow, ShouldRescue
{
    use BroadcastsToDashboard, Dispatchable;

    public function __construct(public Customer $customer) {}

    public function broadcastAs(): string
    {
        return 'customer.changed';
    }

    /**
     * @return array{id: int, code: string}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->customer->id,
            'code' => $this->customer->code,
        ];
    }
}
