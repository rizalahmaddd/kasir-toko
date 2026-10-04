<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hanya sinyal "ada perubahan"; layar mengambil isinya sendiri dari endpoint state, karena isi
 * keranjang bisa melewati batas ukuran pesan Reverb. Channel publik karena layar tidak login;
 * nama channel memuat kunci rahasia layar.
 */
class CustomerDisplayUpdated implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    public function __construct(public string $displayKey, public int $seq) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('display.'.$this->displayKey)];
    }

    public function broadcastAs(): string
    {
        return 'display.updated';
    }

    /**
     * @return array{seq: int}
     */
    public function broadcastWith(): array
    {
        return ['seq' => $this->seq];
    }
}
