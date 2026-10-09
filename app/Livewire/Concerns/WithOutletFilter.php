<?php

namespace App\Livewire\Concerns;

use App\Models\Outlet;
use App\Support\CurrentOutlet;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;

/**
 * Filter outlet untuk daftar dan laporan. Bawaannya outlet yang sedang dipilih. Pengguna dengan izin
 * reports.all-outlets juga bisa memilih "Semua outlet" atau outlet lain; yang lain hanya melihat outlet aktif.
 */
trait WithOutletFilter
{
    #[Url(as: 'outlet')]
    public string $outletFilter = '';

    public function canViewAllOutlets(): bool
    {
        return auth()->user()->can('reports.all-outlets');
    }

    /**
     * Null berarti semua outlet.
     */
    public function outletFilterId(): ?int
    {
        $current = app(CurrentOutlet::class);

        if ($this->canViewAllOutlets()) {
            if ($this->outletFilter === 'all') {
                return null;
            }

            if (ctype_digit($this->outletFilter) && ($current->canAccess((int) $this->outletFilter) || $current->restrictedTo() === null)) {
                return (int) $this->outletFilter;
            }
        }

        return $current->idOrPrimary();
    }

    public function isViewingAllOutlets(): bool
    {
        return $this->outletFilterId() === null;
    }

    /**
     * Pilihan untuk <x-outlet-filter>; kosong bila toko satu outlet atau user tidak boleh memilih.
     *
     * @return Collection<int, Outlet>
     */
    public function outletFilterChoices(): Collection
    {
        $current = app(CurrentOutlet::class);

        if (! $current->isMultiOutlet() || ! $this->canViewAllOutlets()) {
            return new Collection;
        }

        return Outlet::query()->byPriority()->get();
    }
}
