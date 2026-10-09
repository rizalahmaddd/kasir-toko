<?php

namespace App\Livewire\Inventory;

use App\Enums\StockCountScope;
use App\Enums\StockMovementType;
use App\Livewire\Concerns\WithDataTable;
use App\Livewire\Concerns\WithRealtimeRefresh;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductStock;
use App\Models\ProductUnit;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StockMovement;
use App\Services\Pos\PosException;
use App\Services\Pos\StockService;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\NumberFormatter;
use App\Support\NumberFormatter as Num;
use App\Support\TenantRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

    public string $adjustUnitId = '';

    public string $adjustBatchNumber = '';

    public string $adjustExpiresAt = '';

    public string $adjustBatchId = '';

    public string $adjustSerials = '';

    /**
     * Hasil hitung fisik per batch saat opname produk ber-batch, diindeks id batch.
     *
     * @var array<int|string, string>
     */
    public array $opnameCounts = [];

    public string $opnameExtraNumber = '';

    public string $opnameExtraExpiresAt = '';

    public string $opnameExtraQuantity = '';

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

        $product = Product::query()->withOutletData()->findOrFail($productId);
        $this->resetValidation();
        $this->adjustingId = $product->id;
        $this->adjustType = $type;
        $this->adjustQuantity = $type === 'opname' ? self::plainQuantity($product->outletStock()) : '';
        $this->adjustCost = $type === 'stock_in' && $product->cost_price ? (string) $product->cost_price : '';
        $this->adjustNote = '';
        $this->reset('adjustUnitId', 'adjustBatchNumber', 'adjustExpiresAt', 'adjustBatchId', 'adjustSerials', 'opnameExtraNumber', 'opnameExtraExpiresAt', 'opnameExtraQuantity');
        unset($this->adjustingProduct, $this->adjustingBatches);
        $this->fillOpnameCounts();
        $this->dispatch('open-modal', 'stock-adjust');
    }

    private function fillOpnameCounts(): void
    {
        $this->opnameCounts = $this->adjustingBatches->mapWithKeys(fn (ProductBatch $batch) => [$batch->id => self::plainQuantity((float) $batch->quantity)])->all();
    }

    /**
     * Opname produk ber-batch: setiap batch dihitung sendiri, ditambah batch fisik yang belum tercatat.
     */
    public function saveBatchOpname(StockService $stock): void
    {
        abort_unless($this->canAdjust(), 403);

        $this->opnameCounts = array_map(fn ($value) => str_replace(',', '.', trim((string) $value)), $this->opnameCounts);
        $this->opnameExtraQuantity = str_replace(',', '.', trim($this->opnameExtraQuantity));
        $validated = $this->validate([
            'opnameCounts' => ['array'],
            'opnameCounts.*' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'opnameExtraNumber' => ['nullable', 'string', 'max:50'],
            'opnameExtraExpiresAt' => ['nullable', 'date'],
            'opnameExtraQuantity' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'adjustNote' => ['nullable', 'string', 'max:255'],
        ], ['opnameCounts.*.required' => 'Isi hasil hitung batch ini, 0 bila habis.']);

        $product = $this->adjustingProduct;
        abort_unless($product !== null, 404);
        $extra = (float) ($validated['opnameExtraQuantity'] ?: 0) > 0
            ? ['number' => $validated['opnameExtraNumber'] ?: null, 'expires_at' => $validated['opnameExtraExpiresAt'] ?: null, 'quantity' => (float) $validated['opnameExtraQuantity']]
            : null;

        try {
            $movement = $stock->opnameBatches($product, $validated['opnameCounts'], auth()->user(), $validated['adjustNote'] ?: null, null, $extra);
        } catch (PosException $exception) {
            $this->addError('opnameCounts', $exception->getMessage());

            return;
        }

        $this->dispatch('close-modal', 'stock-adjust');
        $this->adjustingId = null;
        $this->dispatch('notify', message: "Opname {$product->name} tersimpan (selisih ".Num::quantity((float) $movement->quantity)." {$product->unit}).");
    }

    public function updatedAdjustType(string $type): void
    {
        $product = $this->adjustingProduct;
        $this->adjustQuantity = $type === 'opname' && $product ? self::plainQuantity($product->outletStock()) : '';
        $this->fillOpnameCounts();
    }

    private static function plainQuantity(string|float $quantity): string
    {
        return str_contains((string) $quantity, '.') ? rtrim(rtrim((string) $quantity, '0'), '.') : (string) $quantity;
    }

    /**
     * Outlet yang sedang dipilih; semua angka stok di halaman ini adalah stok outlet ini.
     */
    #[Computed]
    public function outlet(): ?Outlet
    {
        return app(CurrentOutlet::class)->get();
    }

    #[Computed]
    public function adjustingProduct(): ?Product
    {
        return $this->adjustingId ? Product::query()->withOutletData()->with('units')->find($this->adjustingId) : null;
    }

    /**
     * Batch produk yang sedang disesuaikan di outlet aktif (masih bersaldo), untuk dipilih saat stok keluar.
     *
     * @return Collection<int, ProductBatch>
     */
    #[Computed]
    public function adjustingBatches(): Collection
    {
        $product = $this->adjustingProduct;

        return $product?->tracksBatches() ? $this->batchesOf($product) : new Collection;
    }

    /**
     * @return Collection<int, ProductBatch>
     */
    public function batchesOf(Product $product): Collection
    {
        return ProductBatch::query()
            ->where('product_id', $product->id)
            ->where('outlet_id', app(CurrentOutlet::class)->idOrPrimary())
            ->where('quantity', '!=', 0)
            ->fefo()
            ->get();
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
            'adjustUnitId' => ['nullable', TenantRule::exists('product_units', 'id')->where('product_id', $this->adjustingId)->whereNull('deleted_at')],
            'adjustBatchNumber' => ['nullable', 'string', 'max:50'],
            'adjustExpiresAt' => ['nullable', 'date'],
            'adjustBatchId' => ['nullable', TenantRule::exists('product_batches', 'id')->where('product_id', $this->adjustingId)],
            'adjustSerials' => ['nullable', 'string', 'max:20000'],
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
                null,
                $validated['adjustUnitId'] ? ProductUnit::query()->find((int) $validated['adjustUnitId']) : null,
                [
                    'number' => $validated['adjustBatchNumber'] ?: null,
                    'expires_at' => $validated['adjustExpiresAt'] ?: null,
                    'batch_id' => $validated['adjustBatchId'] ? (int) $validated['adjustBatchId'] : null,
                    'serials' => preg_split('/[\r\n,;]+/', (string) $validated['adjustSerials']) ?: [],
                ],
            );
        } catch (PosException $exception) {
            $this->addError(str_starts_with((string) $exception->reason, 'serial') ? 'adjustSerials' : 'adjustQuantity', $exception->getMessage());

            return;
        }

        $this->dispatch('close-modal', 'stock-adjust');
        $this->adjustingId = null;
        $this->dispatch('notify', message: "{$movement->type->label()} {$product->name} tercatat. Stok sekarang ".NumberFormatter::quantity($movement->stock_after).'.');
    }

    /**
     * Opname yang sedang berjalan di outlet ini.
     *
     * @return Collection<int, StockCount>
     */
    #[Computed]
    public function openCounts(): Collection
    {
        if (! Features::enabled('inventory.opname')) {
            return new Collection;
        }

        return StockCount::query()->open()->where('outlet_id', app(CurrentOutlet::class)->idOrPrimary())->oldest('id')->get();
    }

    /**
     * Dokumen opname berjalan yang memuat tiap produk di halaman ini.
     *
     * @param  iterable<Product>  $products
     * @return array<int, StockCount> product_id => dokumen
     */
    public function countingDocuments(iterable $products): array
    {
        $open = $this->openCounts;

        if ($open->isEmpty()) {
            return [];
        }

        $ids = collect($products)->pluck('id');
        $all = $open->firstWhere('scope', StockCountScope::All);

        if ($all) {
            return $ids->mapWithKeys(fn (int $id) => [$id => $all])->all();
        }

        return StockCountItem::query()->whereIn('stock_count_id', $open->pluck('id'))->whereIn('product_id', $ids)->get(['stock_count_id', 'product_id'])
            ->mapWithKeys(fn (StockCountItem $item) => [$item->product_id => $open->firstWhere('id', $item->stock_count_id)])
            ->all();
    }

    /**
     * @return array{tracked: int, low: int, out: int, value: int}
     */
    #[Computed]
    public function summary(): array
    {
        $tracked = Product::query()->where('track_stock', true)->where('is_active', true);
        $outletId = app(CurrentOutlet::class)->idOrPrimary() ?? 0;

        $value = ProductStock::query()
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->where('product_stocks.outlet_id', $outletId)
            ->where('product_stocks.stock', '>', 0)
            ->where('products.track_stock', true)
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->sum(DB::raw('product_stocks.stock * products.cost_price'));

        return [
            'tracked' => (clone $tracked)->count(),
            'low' => (clone $tracked)->lowStock()->whereOutletStock('>', 0)->count(),
            'out' => (clone $tracked)->outOfStock()->count(),
            'value' => (int) $value,
        ];
    }

    public function export(string $format = 'xlsx')
    {
        $rows = $this->stockQuery()->with('category')->get()->map(fn (Product $product) => [
            $product->sku,
            $product->name,
            $product->category?->name ?: '-',
            NumberFormatter::quantity($product->outletStock()),
            $product->unit,
            NumberFormatter::quantity($product->outletMinStock()),
            NumberFormatter::currency($product->cost_price),
            NumberFormatter::currency(max(0, $product->outletStock()) * $product->cost_price),
        ]);

        $subtitle = now()->translatedFormat('d F Y H:i').($this->outlet && app(CurrentOutlet::class)->isMultiOutlet() ? ' · '.$this->outlet->name : '');

        return $this->exportFormattedResponse('stok', ['SKU', 'Produk', 'Kategori', 'Stok', 'Satuan', 'Stok Minimum', 'HPP', 'Nilai Stok'], $rows, 'Posisi Stok', $subtitle, $format);
    }

    /**
     * @return Builder<Product>
     */
    protected function stockQuery(): Builder
    {
        $query = Product::query()
            ->withOutletData()
            ->where('track_stock', true)
            ->search($this->search)
            ->when($this->level === 'low', fn (Builder $query) => $query->lowStock()->whereOutletStock('>', 0))
            ->when($this->level === 'out', fn (Builder $query) => $query->outOfStock())
            ->when($this->level === '', fn (Builder $query) => $query->where('is_active', true));

        $this->applySorting($query, [
            'name' => 'name',
            'stock' => 'outlet_stock',
            'min_stock' => 'outlet_min_stock',
        ], 'name', 'asc');

        return $query;
    }

    public function render()
    {
        $data = [];

        if ($this->tab === 'movements') {
            $data['movements'] = StockMovement::query()
                ->with(['product', 'user'])
                ->forOutlet(app(CurrentOutlet::class)->idOrPrimary())
                ->when($this->productFilter, fn (Builder $query) => $query->where('product_id', $this->productFilter))
                ->when($this->typeFilter !== '' && StockMovementType::tryFrom($this->typeFilter), fn (Builder $query) => $query->where('type', $this->typeFilter))
                ->when($this->search !== '' && ! $this->productFilter, fn (Builder $query) => $query->whereHas('product', fn (Builder $query) => $query->search($this->search)))
                ->latest('id')
                ->paginate($this->perPage);
            $data['filteredProduct'] = $this->productFilter ? Product::withTrashed()->withOutletData()->with('units')->find($this->productFilter) : null;
            $data['filteredBatches'] = $data['filteredProduct']?->tracksBatches() ? $this->batchesOf($data['filteredProduct']) : new Collection;
        } else {
            $data['products'] = $this->stockQuery()->with(['category', 'units'])->paginate($this->perPage);
        }

        return view('livewire.inventory.stock-index', $data);
    }

    /**
     * @return array<int, string>
     */
    protected function realtimeEvents(): array
    {
        return ['product.changed'];
    }

    /**
     * @return array<int, string>
     */
    protected function realtimeOutletEvents(): array
    {
        return ['sale.recorded', 'stock-count.updated'];
    }
}
