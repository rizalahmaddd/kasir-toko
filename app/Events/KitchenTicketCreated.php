<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToOutlet;
use App\Models\KitchenTicket;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class KitchenTicketCreated implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use BroadcastsToOutlet, Dispatchable;

    public function __construct(public KitchenTicket $ticket) {}

    protected function broadcastOutletId(): ?int
    {
        return $this->ticket->outlet_id;
    }

    protected function alsoToDashboard(): bool
    {
        return false;
    }

    public function broadcastAs(): string
    {
        return 'kitchen.ticket';
    }

    /**
     * @return array{id: int, label: string}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->ticket->id, 'label' => $this->ticket->label];
    }
}
