<?php

namespace App\Livewire\Inventory;

use App\Enums\StockMovementType;
use App\Livewire\Concerns\WithDataTable;
use App\Livewire\Concerns\WithRealtimeRefresh;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Pos\PosException;
use App\Services\Pos\StockService;
use App\Support\NumberFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app', ['heading' => 'Stok Barang'])]
#[Title('Stok Barang')]
class StockIndex extends Component
{
    use WithDataTable, WithRealtimeRefresh;

    #[Url]
    public string $tab = 'stock';

    #[Url]
    public string $search = '';

    #[Url]
    public string $level = '';

    #[Url(as: 'product')]
    public ?int $productFilter = null;

    #[Url(as: 'type')]
    public string $typeFilter = '';

    public ?int $adjustingId = null;

    public string $adjustType = 'stock_in';

    public string $adjustQuantity = '';

    public string $adjustCost = '';

    public string $adjustNote = '';

    public function mount(): void
    {
        if ($this->productFilter) {
            $this->tab = 'movements';
        }
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['tab', 'search', 'level', 'productFilter', 'typeFilter'], true)) {
            $this->resetPage();
        }
    }

    public function canAdjust(): bool
    {
        return auth()->user()->can('inventory.manage');
    }

    public function openAdjust(int $productId, string $type = 'stock_in'): void
    {
        abort_unless($this->canAdjust(), 403);

        $product = Product::findOrFail($productId);
        $this->resetValidation();
        $this->adjustingId = $product->id;
        $this->adjustType = $type;
        $this->adjustQuantity = $type === 'opname' ? self::plainQuantity($product->stock) : '';
        $this->adjustCost = $type === 'stock_in' && $product->cost_price ? (string) $product->cost_price : '';
        $this->adjustNote = '';
        $this->dispatch('open-modal', 'stock-adjust');
    }

    public function updatedAdjustType(string $type): void
    {
        $product = $this->adjustingProduct;
        $this->adjustQuantity = $type === 'opname' && $product ? self::plainQuantity($product->stock) : '';
    }

    private static function plainQuantity(string|float $quantity): string
    {
        return str_contains((string) $quantity, '.') ? rtrim(rtrim((string) $quantity, '0'), '.') : (string) $quantity;
    }

    #[Computed]
    public function adjustingProduct(): ?Product
    {
        return $this->adjustingId ? Product::find($this->adjustingId) : null;
    }

    public function saveAdjustment(StockService $stock): void
    {
        abort_unless($this->canAdjust(), 403);

        $this->adjustQuantity = str_replace(',', '.', trim($this->adjustQuantity));
        $validated = $this->validate([
            'adjustType' => ['required', Rule::in(['stock_in', 'stock_out', 'opname'])],
            'adjustQuantity' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'adjustCost' => ['nullable', 'integer', 'min:0'],
            'adjustNote' => [$this->adjustType === 'stock_out' ? 'required' : 'nullable', 'string', 'max:255'],
        ], [
            'adjustQuantity.required' => 'Isi jumlahnya.',
            'adjustNote.required' => 'Tulis alasan stok keluar, mis. rusak, kedaluwarsa, dipakai sendiri.',
        ]);

        $product = $this->adjustingProduct;
        abort_unless($product !== null, 404);

        try {
            $movement = $stock->adjust(
                $product,
                StockMovementType::from($validated['adjustType']),
                (float) $validated['adjustQuantity'],
                auth()->user(),
                $validated['adjustNote'] ?: null,
                $validated['adjustType'] === 'stock_in' && $validated['adjustCost'] !== '' ? (int) $validated['adjustCost'] : null,
            );
        } catch (PosException $exception) {
            $this->addError('adjustQuantity', $exception->getMessage());

            return;
        }

        $this->dispatch('close-modal', 'stock-adjust');
        $this->adjustingId = null;
        $this->dispatch('notify', message: "{$movement->type->label()} {$product->name} tercatat. Stok sekarang ".NumberFormatter::quantity($movement->stock_after).'.');
    }

    /**
     * @return array{tracked: int, low: int, out: int, value: int}
     */
    #[Computed]
    public function summary(): array
    {
        $tracked = Product::query()->where('track_stock', true)->where('is_active', true);

        return [
            'tracked' => (clone $tracked)->count(),
            'low' => (clone $tracked)->lowStock()->where('stock', '>', 0)->count(),
            'out' => (clone $tracked)->where('stock', '<=', 0)->count(),
            'value' => (int) (clone $tracked)->where('stock', '>', 0)->sum(DB::raw('stock * cost_price')),
        ];
    }

    public function export(string $format = 'xlsx')
    {
        $rows = $this->stockQuery()->with('category')->get()->map(fn (Product $product) => [
            $product->sku,
            $product->name,
            $product->category?->name ?: '-',
            NumberFormatter::quantity($product->stock),
            $product->unit,
            NumberFormatter::quantity($product->min_stock),
            NumberFormatter::currency($product->cost_price),
            NumberFormatter::currency(max(0, (float) $product->stock) * $product->cost_price),
        ]);

        return $this->exportFormattedResponse('stok', ['SKU', 'Produk', 'Kategori', 'Stok', 'Satuan', 'Stok Minimum', 'HPP', 'Nilai Stok'], $rows, 'Posisi Stok', now()->translatedFormat('d F Y H:i'), $format);
    }

    /**
     * @return Builder<Product>
     */
    protected function stockQuery(): Builder
    {
        $query = Product::query()
            ->where('track_stock', true)
            ->search($this->search)
            ->when($this->level === 'low', fn (Builder $query) => $query->lowStock()->where('stock', '>', 0))
            ->when($this->level === 'out', fn (Builder $query) => $query->where('stock', '<=', 0))
            ->when($this->level === '', fn (Builder $query) => $query->where('is_active', true));

        $this->applySorting($query, [
            'name' => 'name',
            'stock' => 'stock',
            'min_stock' => 'min_stock',
        ], 'name', 'asc');

        return $query;
    }

    public function render()
    {
        $data = [];

        if ($this->tab === 'movements') {
            $data['movements'] = StockMovement::query()
                ->with(['product', 'user'])
                ->when($this->productFilter, fn (Builder $query) => $query->where('product_id', $this->productFilter))
                ->when($this->typeFilter !== '' && StockMovementType::tryFrom($this->typeFilter), fn (Builder $query) => $query->where('type', $this->typeFilter))
                ->when($this->search !== '' && ! $this->productFilter, fn (Builder $query) => $query->whereHas('product', fn (Builder $query) => $query->search($this->search)))
                ->latest('id')
                ->paginate($this->perPage);
            $data['filteredProduct'] = $this->productFilter ? Product::withTrashed()->find($this->productFilter) : null;
        } else {
            $data['products'] = $this->stockQuery()->with('category')->paginate($this->perPage);
        }

        return view('livewire.inventory.stock-index', $data);
    }

    /**
     * @return array<int, string>
     */
    protected function realtimeEvents(): array
    {
        return ['product.changed', 'sale.recorded'];
    }
}
