<?php

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\WithDataTable;
use App\Livewire\Concerns\WithRealtimeRefresh;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Services\Pos\PosException;
use App\Services\Pos\StockTransferService;
use App\Support\CurrentOutlet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app', ['heading' => 'Transfer Stok'])]
#[Title('Transfer Stok')]
class StockTransfers extends Component
{
    use WithDataTable, WithRealtimeRefresh;

    #[Url]
    public string $status = '';

    public string $fromOutletId = '';

    public string $toOutletId = '';

    public string $note = '';

    public string $productSearch = '';

    /** @var list<array{product_id: int, name: string, unit: string, available: float, quantity: string}> */
    public array $items = [];

    public ?int $viewingId = null;

    public ?int $cancellingId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('inventory.transfer'), 403);
    }

    public function updating(string $name): void
    {
        if ($name === 'status') {
            $this->resetPage();
        }
    }

    /**
     * Outlet yang boleh dipakai user untuk memindahkan stok (dapat diakses dan tidak terkunci).
     *
     * @return Collection<int, Outlet>
     */
    #[Computed]
    public function outlets(): Collection
    {
        $ids = app(CurrentOutlet::class)->operationalIds();

        return Outlet::query()->whereIn('id', $ids)->byPriority()->get();
    }

    public function openCreate(): void
    {
        $this->resetValidation();
        $this->reset(['toOutletId', 'note', 'productSearch', 'items']);
        $this->fromOutletId = (string) (app(CurrentOutlet::class)->id() ?? '');
        $this->dispatch('open-modal', 'transfer-form');
    }

    public function closeForm(): void
    {
        $this->reset(['fromOutletId', 'toOutletId', 'note', 'productSearch', 'items']);
        $this->resetValidation();
        $this->dispatch('close-modal', 'transfer-form');
    }

    public function updatedFromOutletId(): void
    {
        $this->items = [];
    }

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function productMatches(): Collection
    {
        $term = trim($this->productSearch);

        if ($term === '' || $this->fromOutletId === '') {
            return new Collection;
        }

        return Product::query()
            ->withOutletData((int) $this->fromOutletId)
            ->where('track_stock', true)
            ->where('is_active', true)
            ->search($term)
            ->orderBy('name')
            ->limit(8)
            ->get();
    }

    public function addProduct(int $productId): void
    {
        $product = Product::query()->withOutletData((int) $this->fromOutletId)->where('track_stock', true)->find($productId);

        if (! $product || collect($this->items)->contains('product_id', $productId)) {
            return;
        }

        $this->items[] = [
            'product_id' => $product->id,
            'name' => $product->name,
            'unit' => $product->unit,
            'available' => $product->outletStock(),
            'quantity' => '1',
        ];
        $this->productSearch = '';
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function save(StockTransferService $transfers): void
    {
        abort_unless(auth()->user()->can('inventory.transfer'), 403);

        $this->validate([
            'fromOutletId' => ['required', 'integer'],
            'toOutletId' => ['required', 'integer', 'different:fromOutletId'],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
        ], [
            'fromOutletId.required' => 'Pilih outlet asal.',
            'toOutletId.required' => 'Pilih outlet tujuan.',
            'toOutletId.different' => 'Outlet tujuan harus berbeda dengan outlet asal.',
            'items.required' => 'Tambahkan minimal satu produk.',
            'items.min' => 'Tambahkan minimal satu produk.',
            'items.*.quantity.gt' => 'Jumlah harus lebih dari 0.',
        ]);

        try {
            $transfer = $transfers->create(
                auth()->user(),
                (int) $this->fromOutletId,
                (int) $this->toOutletId,
                collect($this->items)->map(fn (array $item) => ['product_id' => $item['product_id'], 'quantity' => str_replace(',', '.', (string) $item['quantity'])])->all(),
                trim($this->note) ?: null,
            );
        } catch (PosException $exception) {
            $this->addError('items', $exception->getMessage());

            return;
        }

        $this->closeForm();
        $this->dispatch('notify', message: "Transfer {$transfer->number} selesai. Stok sudah berpindah.");
    }

    public function view(int $id): void
    {
        $this->viewingId = StockTransfer::query()->findOrFail($id)->id;
        $this->dispatch('open-modal', 'transfer-detail');
    }

    #[Computed]
    public function viewing(): ?StockTransfer
    {
        return $this->viewingId ? StockTransfer::query()->with(['items.product', 'fromOutlet', 'toOutlet', 'creator', 'canceller'])->find($this->viewingId) : null;
    }

    public function confirmCancel(int $id): void
    {
        $this->cancellingId = StockTransfer::query()->findOrFail($id)->id;
        $this->dispatch('open-modal', 'transfer-cancel');
    }

    public function cancelTransfer(StockTransferService $transfers): void
    {
        abort_unless(auth()->user()->can('inventory.transfer'), 403);

        $transfer = StockTransfer::query()->findOrFail($this->cancellingId);

        try {
            $transfers->cancel($transfer, auth()->user());
        } catch (PosException $exception) {
            $this->dispatch('close-modal', 'transfer-cancel');
            $this->dispatch('notify', message: $exception->getMessage(), type: 'error');

            return;
        }

        $this->cancellingId = null;
        $this->dispatch('close-modal', 'transfer-cancel');
        $this->dispatch('close-modal', 'transfer-detail');
        $this->dispatch('notify', message: "Transfer {$transfer->number} dibatalkan, stok dikembalikan.");
    }

    /**
     * @return array<int, string>
     */
    protected function realtimeEvents(): array
    {
        return ['product.changed'];
    }

    public function render()
    {
        $transfers = StockTransfer::query()
            ->with(['fromOutlet', 'toOutlet', 'creator'])
            ->withCount('items')
            ->when(in_array($this->status, [StockTransfer::STATUS_COMPLETED, StockTransfer::STATUS_CANCELLED], true), fn (Builder $query) => $query->where('status', $this->status))
            ->latest('id')
            ->paginate($this->perPage);

        return view('livewire.inventory.stock-transfers', ['transfers' => $transfers]);
    }
}
