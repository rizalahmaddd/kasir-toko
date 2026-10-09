<?php

namespace App\Livewire\Inventory;

use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use App\Livewire\Concerns\WithDataTable;
use App\Livewire\Concerns\WithRealtimeRefresh;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockCount;
use App\Services\Pos\PosException;
use App\Services\Pos\StockCountService;
use App\Support\CurrentOutlet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app', ['heading' => 'Stok Opname'])]
#[Title('Stok Opname')]
class StockCounts extends Component
{
    use WithDataTable, WithRealtimeRefresh;

    public const TABS = ['open' => 'Berjalan', 'posted' => 'Selesai', 'cancelled' => 'Dibatalkan'];

    #[Url]
    public string $tab = 'open';

    public string $scope = 'all';

    /** @var list<int|string> */
    public array $categoryIds = [];

    /** @var list<array{id: int, name: string, sku: ?string}> */
    public array $pickedProducts = [];

    public string $productSearch = '';

    public bool $blindCount = true;

    public bool $holdAdjustments = true;

    public bool $holdTouched = false;

    public string $note = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('inventory.opname.count'), 403);
    }

    public function updatingTab(): void
    {
        $this->resetPage();
    }

    public function canManage(): bool
    {
        return auth()->user()->can('inventory.opname.manage');
    }

    public function openStart(): void
    {
        abort_unless($this->canManage(), 403);

        $this->resetValidation();
        $this->reset(['scope', 'categoryIds', 'pickedProducts', 'productSearch', 'blindCount', 'holdAdjustments', 'holdTouched', 'note']);
        $this->dispatch('open-modal', 'stock-count-start');
    }

    public function closeStart(): void
    {
        $this->resetValidation();
        $this->dispatch('close-modal', 'stock-count-start');
    }

    public function updatedScope(string $scope): void
    {
        if (! $this->holdTouched) {
            $this->holdAdjustments = $scope === StockCountScope::All->value;
        }
    }

    public function updatedHoldAdjustments(): void
    {
        $this->holdTouched = true;
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function productMatches(): Collection
    {
        $term = trim($this->productSearch);

        if ($term === '') {
            return new Collection;
        }

        return Product::query()
            ->where('track_stock', true)
            ->search($term)
            ->whereNotIn('id', collect($this->pickedProducts)->pluck('id'))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'sku', 'variant_options']);
    }

    public function pickProduct(int $productId): void
    {
        $product = Product::query()->where('track_stock', true)->find($productId);

        if ($product && ! collect($this->pickedProducts)->contains('id', $product->id)) {
            $this->pickedProducts[] = ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku];
        }

        $this->productSearch = '';
    }

    /**
     * Enter di kolom cari: barcode/SKU yang cocok persis langsung dipilih, supaya bisa memilih barang dengan scanner.
     */
    public function pickFirstMatch(): void
    {
        $match = $this->productMatches->first();

        if ($match) {
            $this->pickProduct($match->id);
        }
    }

    public function unpickProduct(int $productId): void
    {
        $this->pickedProducts = array_values(array_filter($this->pickedProducts, fn (array $product) => $product['id'] !== $productId));
    }

    public function start(StockCountService $counts)
    {
        abort_unless($this->canManage(), 403);

        $this->validate([
            'scope' => ['required', 'in:'.implode(',', array_column(StockCountScope::cases(), 'value'))],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $outletId = app(CurrentOutlet::class)->idOrPrimary();

        if ($outletId === null) {
            $this->addError('scope', 'Toko belum punya outlet.');

            return null;
        }

        try {
            $count = $counts->start(
                auth()->user(),
                $outletId,
                StockCountScope::from($this->scope),
                array_map('intval', $this->categoryIds),
                collect($this->pickedProducts)->pluck('id')->all(),
                $this->blindCount,
                $this->holdAdjustments,
                trim($this->note) ?: null,
            );
        } catch (PosException $exception) {
            $this->addError('scope', $exception->getMessage());

            return null;
        }

        $this->dispatch('close-modal', 'stock-count-start');

        return $this->redirectRoute('inventory.opname.show', $count, navigate: true);
    }

    /**
     * @return array<int, string>
     */
    protected function realtimeOutletEvents(): array
    {
        return ['stock-count.updated'];
    }

    public function render()
    {
        $counts = StockCount::query()
            ->with(['outlet', 'creator'])
            ->withCount([
                'items',
                'items as counted_items_count' => fn (Builder $query) => $query->whereNotNull('counted_qty'),
            ])
            ->where('outlet_id', app(CurrentOutlet::class)->idOrPrimary())
            ->when($this->tab === 'open', fn (Builder $query) => $query->open())
            ->when($this->tab === 'posted', fn (Builder $query) => $query->where('status', StockCountStatus::Posted))
            ->when($this->tab === 'cancelled', fn (Builder $query) => $query->where('status', StockCountStatus::Cancelled))
            ->latest('id')
            ->paginate($this->perPage);

        return view('livewire.inventory.stock-counts', [
            'counts' => $counts,
            'multiOutlet' => app(CurrentOutlet::class)->isMultiOutlet(),
        ]);
    }
}
