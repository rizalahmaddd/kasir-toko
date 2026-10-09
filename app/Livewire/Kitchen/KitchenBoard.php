<?php

namespace App\Livewire\Kitchen;

use App\Livewire\Concerns\WithRealtimeRefresh;
use App\Models\KitchenTicket;
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

    public function markDone(int $id): void
    {
        $ticket = KitchenTicket::query()->whereKey($id)->where('status', KitchenTicket::STATUS_PENDING)->first();

        if ($ticket) {
            $ticket->update(['status' => KitchenTicket::STATUS_DONE, 'done_at' => now()]);
            $this->dispatch('notify', message: "Pesanan {$ticket->label} selesai.");
        }

        unset($this->tickets);
    }

    public function reopen(int $id): void
    {
        KitchenTicket::query()->whereKey($id)->first()?->update(['status' => KitchenTicket::STATUS_PENDING, 'done_at' => null]);
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
        return view('livewire.kitchen.kitchen-board', [
            'pendingCount' => KitchenTicket::query()->where('outlet_id', app(CurrentOutlet::class)->idOrPrimary())->where('status', KitchenTicket::STATUS_PENDING)->count(),
        ]);
    }
}
