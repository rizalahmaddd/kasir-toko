<?php

namespace App\Livewire\Layout;

use App\Http\Middleware\IdentifyOutlet;
use App\Models\Outlet;
use App\Support\CurrentOutlet;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * Pemilih outlet di header. Hanya muncul untuk toko dengan lebih dari satu outlet dan user yang
 * boleh memakai lebih dari satu; pilihannya disimpan di session dan sebagai outlet terakhir dipakai.
 */
class OutletSwitcher extends Component
{
    public function switchTo(int $outletId): void
    {
        $current = app(CurrentOutlet::class);

        abort_unless($current->canAccess($outletId), 403);

        session([IdentifyOutlet::SESSION_KEY => $outletId]);
        auth()->user()->forceFill(['default_outlet_id' => $outletId])->saveQuietly();

        $this->redirect(url()->previous(route('dashboard')), navigate: true);
    }

    /**
     * @return Collection<int, Outlet>
     */
    private function choices(): Collection
    {
        return Outlet::query()->whereIn('id', app(CurrentOutlet::class)->accessibleIds())->byPriority()->get();
    }

    public function render()
    {
        $current = app(CurrentOutlet::class);
        $choices = $current->isMultiOutlet() ? $this->choices() : new Collection;

        return view('livewire.layout.outlet-switcher', [
            'choices' => $choices,
            'currentId' => $current->id(),
            'operationalIds' => $current->operationalIds(),
        ]);
    }
}
