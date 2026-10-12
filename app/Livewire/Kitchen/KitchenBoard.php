<?php

namespace App\Livewire\Kitchen;

use App\Livewire\Concerns\WithRealtimeRefresh;
use App\Models\KitchenTicket;
use App\Services\Pos\KitchenTicketService;
use App\Support\CurrentOutlet;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Layar dapur: tiket pesanan outlet aktif yang belum disiapkan, terlama di depan. Tiket baru masuk lewat event
 * realtime outlet; polling lambat tetap jalan sebagai cadangan bila koneksi websocket putus.
 */
#[Layout('layouts.app', ['heading' => 'Layar Dapur'])]
#[Title('Layar Dapur')]
class KitchenBoard extends Component
{
    use WithRealtimeRefresh;

    #[Url]
    public string $tab = 'pending';

    /**
     * Properti publik, bukan data view: layar membunyikan notifikasi dengan memantau `$wire.pendingCount`,
     * dan Alpine memanggil nama yang bukan properti sebagai method (error "Public method not found").
     */
    public int $pendingCount = 0;

    /**
     * @return Collection<int, KitchenTicket>
     */
    #[Computed]
    public function tickets(): Collection
    {
        return KitchenTicket::query()
            ->with('user', 'sale')
            ->where('outlet_id', app(CurrentOutlet::class)->idOrPrimary())
            ->when($this->tab === 'done', fn ($query) => $query->where('status', KitchenTicket::STATUS_DONE)->where('done_at', '>=', today())->latest('done_at'))
            ->when($this->tab !== 'done', fn ($query) => $query->where('status', KitchenTicket::STATUS_PENDING)->oldest())
            ->limit(60)
            ->get();
    }

    public function markDone(int $id, KitchenTicketService $kitchen): void
    {
        $ticket = KitchenTicket::query()->whereKey($id)->where('status', KitchenTicket::STATUS_PENDING)->first();

        if ($ticket) {
            $kitchen->markDone($ticket);
            $this->dispatch('notify', message: "Pesanan {$ticket->label} selesai.");
        }

        unset($this->tickets);
    }

    public function reopen(int $id, KitchenTicketService $kitchen): void
    {
        $ticket = KitchenTicket::query()->whereKey($id)->first();

        if ($ticket) {
            $kitchen->reopen($ticket);
        }

        unset($this->tickets);
    }

    /**
     * @return array<int, string>
     */
    protected function realtimeOutletEvents(): array
    {
        return ['kitchen.ticket'];
    }

    public function render()
    {
        $this->pendingCount = KitchenTicket::query()->where('outlet_id', app(CurrentOutlet::class)->idOrPrimary())->where('status', KitchenTicket::STATUS_PENDING)->count();

        return view('livewire.kitchen.kitchen-board');
    }
}
