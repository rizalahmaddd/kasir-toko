<?php

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\WithDataTable;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Services\Pos\PosException;
use App\Services\Pos\SerialService;
use App\Services\Pos\StockService;
use App\Support\CurrentOutlet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Unit bernomor seri/IMEI di outlet aktif: cari nomor seri, lihat status & transaksinya, dan daftarkan nomor
 * seri untuk stok lama yang masuk sebelum produknya memakai nomor seri.
 */
#[Layout('layouts.app', ['heading' => 'Nomor Seri / IMEI'])]
#[Title('Nomor Seri')]
class SerialNumbers extends Component
{
    use WithDataTable;

    public string $search = '';

    #[Url]
    public string $status = ProductSerial::IN_STOCK;

    public string $productId = '';

    public string $serialText = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function serialProducts(): Collection
    {
        return Product::query()->withOutletData()->where('track_serial', true)->where('track_stock', true)->orderBy('name')->get();
    }

    public function openRegister(): void
    {
        abort_unless(auth()->user()->can('inventory.manage'), 403);

        $this->reset('productId', 'serialText');
        $this->resetValidation();
        $this->dispatch('open-modal', 'register-serials');
    }

    public function register(SerialService $serials, StockService $stock): void
    {
        abort_unless(auth()->user()->can('inventory.manage'), 403);

        $this->validate(['productId' => ['required', 'integer'], 'serialText' => ['required', 'string', 'max:20000']], [
            'productId.required' => 'Pilih produknya.',
            'serialText.required' => 'Isi nomor seri, satu per baris.',
        ]);

        $outletId = app(CurrentOutlet::class)->idOrPrimary();

        try {
            $count = DB::transaction(function () use ($serials, $stock, $outletId) {
                $product = Product::query()->whereKey($this->productId)->lockForUpdate()->firstOrFail();
                $outletStock = (float) $stock->lockStock($product, $outletId)->stock;

                return $serials->register($product, $outletId, preg_split('/[\r\n,;]+/', $this->serialText) ?: [], $outletStock);
            });
        } catch (PosException $exception) {
            $this->addError('serialText', $exception->getMessage());

            return;
        }

        $this->dispatch('close-modal', 'register-serials');
        $this->dispatch('notify', message: "{$count} nomor seri didaftarkan.");
    }

    public function render()
    {
        $serials = ProductSerial::query()
            ->with(['product', 'saleItem.sale'])
            ->where('outlet_id', app(CurrentOutlet::class)->idOrPrimary())
            ->when($this->status !== 'all', fn (Builder $query) => $query->where('status', $this->status))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('serial', 'like', '%'.mb_strtoupper(trim($this->search)).'%')
                ->orWhereHas('product', fn (Builder $query) => $query->where('name', 'like', '%'.trim($this->search).'%'))))
            ->latest('id')
            ->paginate($this->perPage);

        $unregistered = $this->serialProducts
            ->map(fn (Product $product) => ['product' => $product, 'missing' => (int) floor($product->outletStock()) - ProductSerial::query()->where('product_id', $product->id)->where('outlet_id', app(CurrentOutlet::class)->idOrPrimary())->available()->count()])
            ->filter(fn (array $row) => $row['missing'] > 0)
            ->values();

        return view('livewire.inventory.serial-numbers', ['serials' => $serials, 'unregistered' => $unregistered]);
    }
}
