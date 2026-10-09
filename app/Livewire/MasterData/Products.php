<?php

namespace App\Livewire\MasterData;

use App\Enums\DrugClass;
use App\Enums\StockMovementType;
use App\Livewire\Concerns\WithCrudActions;
use App\Livewire\Concerns\WithRealtimeRefresh;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductOutletPrice;
use App\Models\ProductStock;
use App\Models\ProductUnit;
use App\Services\DocumentNumberGenerator;
use App\Services\OutletService;
use App\Services\Pos\StockService;
use App\Services\ProductCapabilityData;
use App\Services\ProductImportService;
use App\Services\VariantService;
use App\Support\CurrentOutlet;
use App\Support\CurrentTenant;
use App\Support\NumberFormatter;
use App\Support\PlanLimits;
use App\Support\ProductAttributes;
use App\Support\StorePresets\AttributeField;
use App\Support\TenantRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.app', ['heading' => 'Produk'])]
#[Title('Produk')]
class Products extends Component
{
    use WithCrudActions, WithFileUploads, WithRealtimeRefresh;

    #[Url(as: 'category')]
    public string $categoryFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    public string $category_id = '';

    public string $sku = '';

    public string $barcode = '';

    public string $name = '';

    public string $unit = 'pcs';

    public string $cost_price = '';

    public string $price = '';

    public bool $track_stock = true;

    public string $stock = '0';

    public string $min_stock = '0';

    public bool $is_active = true;

    /**
     * Harga jual khusus outlet, diindeks id outlet; kosong berarti mengikuti harga bawaan produk.
     *
     * @var array<int|string, string>
     */
    public array $outletPrices = [];

    /**
     * Stok minimum khusus outlet, diindeks id outlet; kosong berarti mengikuti batas bawaan produk.
     *
     * @var array<int|string, string>
     */
    public array $outletMinStocks = [];

    /**
     * @var array<string, mixed>
     */
    public array $custom_attributes = [];

    public string $drug_class = '';

    public bool $requires_prescription = false;

    public bool $track_batch = false;

    public string $initial_batch_number = '';

    public string $initial_expires_at = '';

    /**
     * Satuan jual tambahan; stok tetap dicatat dalam satuan dasar ($unit).
     *
     * @var list<array{id: ?int, name: string, factor: string, price: string, barcode: string, is_default_sale: bool}>
     */
    public array $units = [];

    /**
     * @var list<array{min_quantity: string, price: string}>
     */
    public array $price_tiers = [];

    /**
     * @var list<int|string>
     */
    public array $modifier_group_ids = [];

    /**
     * @var list<array{component_id: string, quantity: string}>
     */
    public array $components = [];

    /**
     * Pilihan varian di produk induk, nilai dipisah koma (mis. Ukuran: S, M, L).
     *
     * @var list<array{name: string, values: string}>
     */
    public array $variant_options = [];

    public bool $track_serial = false;

    public string $warranty_days = '';

    /** @var TemporaryUploadedFile|null */
    public $image = null;

    public ?string $currentImageUrl = null;

    public bool $removeImage = false;

    /** @var TemporaryUploadedFile|null */
    public $importFile = null;

    /** @var array{imported: int, skipped: int, errors: list<string>}|null */
    public ?array $importSummary = null;

    public const UNITS = ['pcs', 'bks', 'btl', 'dus', 'pak', 'kg', 'gram', 'liter', 'ml', 'porsi', 'gelas', 'lusin', 'sachet', 'lembar', 'meter'];

    public function mount(): void
    {
        $this->search = mb_substr((string) request()->query('search', ''), 0, 100);
    }

    public function openImportModal(): void
    {
        $this->authorizeManage();
        $this->importFile = null;
        $this->importSummary = null;
        $this->dispatch('open-modal', 'import-products-modal');
    }

    public function downloadTemplate(ProductImportService $importer)
    {
        $this->authorizeManage();

        return $importer->downloadTemplate();
    }

    public function processImport(ProductImportService $importer): void
    {
        $this->authorizeManage();

        $this->validate([
            'importFile' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120'],
        ], [], ['importFile' => 'file import']);

        $result = $importer->import($this->importFile);
        $this->importSummary = $result;

        if ($result['imported'] > 0) {
            $this->notify("Berhasil mengimpor {$result['imported']} produk.");
        }
    }

    public function updatingCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Outlet yang bisa diberi harga dan batas stok sendiri; kosong untuk toko satu outlet.
     *
     * @return Collection<int, Outlet>
     */
    public function outletChoices(): Collection
    {
        $current = app(CurrentOutlet::class);

        return $current->isMultiOutlet() ? Outlet::query()->whereIn('id', $current->accessibleIds())->byPriority()->get() : new Collection;
    }

    public function addUnit(): void
    {
        if (count($this->units) < 10) {
            $this->units[] = ['id' => null, 'name' => '', 'factor' => '', 'price' => '', 'barcode' => '', 'is_default_sale' => false];
        }
    }

    public function removeUnit(int $index): void
    {
        unset($this->units[$index]);
        $this->units = array_values($this->units);
    }

    public function addTier(): void
    {
        if (count($this->price_tiers) < 10) {
            $this->price_tiers[] = ['min_quantity' => '', 'price' => ''];
        }
    }

    public function removeTier(int $index): void
    {
        unset($this->price_tiers[$index]);
        $this->price_tiers = array_values($this->price_tiers);
    }

    public function addVariantOption(): void
    {
        if (count($this->variant_options) < 3) {
            $this->variant_options[] = ['name' => '', 'values' => ''];
        }
    }

    public function removeVariantOption(int $index): void
    {
        unset($this->variant_options[$index]);
        $this->variant_options = array_values($this->variant_options);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function variantChildren(): \Illuminate\Support\Collection
    {
        return $this->editingId ? VariantService::summary(Product::query()->findOrFail($this->editingId)) : collect();
    }

    public function addComponent(): void
    {
        if (count($this->components) < 20) {
            $this->components[] = ['component_id' => '', 'quantity' => ''];
        }
    }

    public function removeComponent(int $index): void
    {
        unset($this->components[$index]);
        $this->components = array_values($this->components);
    }

    /**
     * @return Collection<int, ModifierGroup>
     */
    public function modifierGroupChoices(): Collection
    {
        return ModifierGroup::query()->orderBy('name')->get();
    }

    /**
     * Bahan racikan: produk berstok yang bukan produk ini.
     *
     * @return Collection<int, Product>
     */
    public function componentChoices(): Collection
    {
        return Product::query()->where('track_stock', true)->when($this->editingId, fn (Builder $query) => $query->whereKeyNot($this->editingId))->orderBy('name')->limit(500)->get(['id', 'name', 'unit']);
    }

    public function updatedDrugClass(string $value): void
    {
        $this->requires_prescription = DrugClass::tryFrom($value)?->requiresPrescriptionByDefault() ?? false;
    }

    /**
     * @return list<AttributeField>
     */
    public function attributeFields(): array
    {
        return ProductAttributes::fields();
    }

    /**
     * @return list<string>
     */
    public function unitSuggestions(): array
    {
        return array_values(array_unique([...ProductAttributes::suggestedUnits(), ...self::UNITS]));
    }

    public function save(DocumentNumberGenerator $numbers, StockService $stockService, OutletService $outlets, ProductCapabilityData $capabilityData): void
    {
        $this->authorizeManage();
        $this->units = collect($this->units)->map(fn (array $unit) => [
            ...$unit,
            'name' => trim((string) $unit['name']),
            'factor' => str_replace(',', '.', trim((string) $unit['factor'])),
            'price' => preg_replace('/\D/', '', (string) $unit['price']) ?? '',
            'barcode' => trim((string) $unit['barcode']),
        ])->values()->all();
        $this->price_tiers = collect($this->price_tiers)->map(fn (array $tier) => [
            'min_quantity' => str_replace(',', '.', trim((string) $tier['min_quantity'])),
            'price' => preg_replace('/\D/', '', (string) $tier['price']) ?? '',
        ])->values()->all();
        $this->components = collect($this->components)->map(fn (array $row) => [
            'component_id' => (string) $row['component_id'],
            'quantity' => str_replace(',', '.', trim((string) $row['quantity'])),
        ])->values()->all();
        if ($this->track_stock) {
            $this->components = [];
        }

        $this->sku = strtoupper(trim($this->sku));
        $this->barcode = trim($this->barcode);
        $this->min_stock = str_replace(',', '.', trim($this->min_stock));
        if (! $this->editingId) {
            $this->stock = str_replace(',', '.', trim($this->stock));
        }
        $this->price = preg_replace('/\D/', '', $this->price) ?? '';
        $this->cost_price = preg_replace('/\D/', '', $this->cost_price) ?? '';
        $this->outletPrices = collect($this->outletPrices)->map(fn ($value) => preg_replace('/\D/', '', (string) $value) ?? '')->all();
        $this->outletMinStocks = collect($this->outletMinStocks)->map(fn ($value) => str_replace(',', '.', trim((string) $value)))->all();
        $validated = $this->validate();
        $isEditing = (bool) $this->editingId;
        ProductCapabilityData::ensureUniqueBarcodes($this->editingId, $validated['barcode'] ?? null, $validated['units'] ?? []);

        if (! $isEditing) {
            PlanLimits::ensureCanAdd('products', 'name');
        }

        $attributes = [
            'category_id' => $validated['category_id'] ?: null,
            'sku' => $validated['sku'] ?: $numbers->next('PRD', 5),
            'barcode' => $validated['barcode'] ?: null,
            'name' => $validated['name'],
            'unit' => $validated['unit'],
            'cost_price' => (int) ($validated['cost_price'] ?: 0),
            'price' => (int) $validated['price'],
            'track_stock' => $validated['track_stock'],
            'min_stock' => $validated['track_stock'] ? (float) $validated['min_stock'] : 0,
            'is_active' => $validated['is_active'],
        ];

        DB::transaction(function () use ($isEditing, $attributes, $validated, $stockService, $outlets, $capabilityData) {
            if ($isEditing) {
                $product = Product::findOrFail($this->editingId);
                $product->fill($attributes);
            } else {
                $product = new Product($attributes + ['stock' => 0]);
            }

            if ($this->image) {
                $old = $product->image_path;
                $product->image_path = $this->image->store(app(CurrentTenant::class)->storagePath('products'), 'public');
                $old && Storage::disk('public')->delete($old);
            } elseif ($this->removeImage && $product->image_path) {
                Storage::disk('public')->delete($product->image_path);
                $product->image_path = null;
            }

            $capabilityData->fill($product, $validated);
            $product->save();
            $capabilityData->afterSave($product, $validated);

            $this->saveOutletOverrides($product, $outlets, $stockService);

            $initialStock = (float) ($validated['stock'] ?? 0);
            if (! $isEditing) {
                ProductCapabilityData::ensureInitialStockAllowed($product, $initialStock);
            }
            if (! $isEditing && $product->track_stock && $initialStock != 0.0) {
                $stockService->move($product, StockMovementType::Initial, $initialStock, auth()->user(), null, 'Stok awal saat produk dibuat', $product->cost_price, null, [
                    'number' => $validated['initial_batch_number'] ?? null,
                    'expires_at' => $validated['initial_expires_at'] ?? null,
                ]);
            }
        });

        $this->closeModal();
        $this->notify($isEditing ? 'Produk diperbarui.' : 'Produk ditambahkan.');
    }

    private function saveOutletOverrides(Product $product, OutletService $outlets, StockService $stockService): void
    {
        foreach ($this->outletChoices() as $outlet) {
            $price = $this->outletPrices[$outlet->id] ?? '';
            $outlets->setProductPrice($product->id, $outlet->id, $price === '' ? null : (int) $price);

            $min = $this->outletMinStocks[$outlet->id] ?? '';

            if ($product->track_stock && ($min !== '' || ProductStock::query()->where('product_id', $product->id)->where('outlet_id', $outlet->id)->whereNotNull('min_stock')->exists())) {
                $row = $stockService->lockStock($product, $outlet->id);
                $row->update(['min_stock' => $min === '' ? null : (float) $min]);
            }
        }
    }

    public function toggleActive(int $id): void
    {
        $this->authorizeManage();

        $product = Product::findOrFail($id);
        $product->update(['is_active' => ! $product->is_active]);
        $this->notify($product->is_active ? "{$product->name} dijual lagi di kasir." : "{$product->name} disembunyikan dari kasir.");
    }

    public function export(string $format = 'xlsx')
    {
        $rows = $this->query()->with('category')->get()->map(fn (Product $product) => [
            $product->sku,
            $product->barcode ?: '-',
            $product->name,
            $product->category?->name ?: '-',
            $product->unit,
            NumberFormatter::currency($product->cost_price),
            NumberFormatter::currency($product->effectivePrice()),
            $product->track_stock ? NumberFormatter::quantity($product->outletStock()) : 'Tidak dilacak',
            $product->is_active ? 'Aktif' : 'Nonaktif',
        ]);

        return $this->exportFormattedResponse('produk', ['SKU', 'Barcode', 'Nama Produk', 'Kategori', 'Satuan', 'HPP', 'Harga Jual', 'Stok', 'Status'], $rows, 'Daftar Produk', null, $format);
    }

    /**
     * @return Builder<Product>
     */
    protected function query(): Builder
    {
        $query = Product::query()
            ->withOutletData()
            ->search($this->search)
            ->when($this->categoryFilter === 'none', fn (Builder $query) => $query->whereNull('category_id'))
            ->when(ctype_digit($this->categoryFilter), fn (Builder $query) => $query->where('category_id', (int) $this->categoryFilter))
            ->when($this->statusFilter === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($this->statusFilter === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->when($this->statusFilter === 'low', fn (Builder $query) => $query->lowStock());

        $this->applySorting($query, [
            'sku' => 'sku',
            'name' => 'name',
            'price' => 'outlet_price',
            'cost_price' => 'cost_price',
            'stock' => 'outlet_stock',
            'is_active' => 'is_active',
        ], 'name', 'asc');

        return $query;
    }

    public function render()
    {
        return view('livewire.master-data.products', [
            'products' => $this->query()->with('category')->paginate($this->perPage),
            'categories' => Category::query()->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function realtimeEvents(): array
    {
        return ['product.changed', 'sale.recorded'];
    }

    protected function modelClass(): string
    {
        return Product::class;
    }

    protected function resetForm(): void
    {
        $this->reset(['category_id', 'sku', 'barcode', 'name', 'cost_price', 'price', 'image', 'currentImageUrl', 'removeImage', 'outletPrices', 'outletMinStocks', 'custom_attributes', 'drug_class', 'requires_prescription', 'track_batch', 'initial_batch_number', 'initial_expires_at', 'units', 'price_tiers', 'modifier_group_ids', 'components', 'variant_options', 'track_serial', 'warranty_days']);
        $this->unit = 'pcs';
        $this->track_stock = true;
        $this->stock = '0';
        $this->min_stock = '0';
        $this->is_active = true;
    }

    protected function fillForm($record): void
    {
        $this->category_id = (string) $record->category_id;
        $this->sku = $record->sku;
        $this->barcode = (string) $record->barcode;
        $this->name = $record->name;
        $this->unit = $record->unit;
        $this->cost_price = (string) $record->cost_price;
        $this->price = (string) $record->price;
        $this->track_stock = $record->track_stock;
        $outletStock = Product::query()->withOutletData()->find($record->id)?->outletStock() ?? (float) $record->stock;
        $this->stock = rtrim(rtrim(number_format($outletStock, 3, '.', ''), '0'), '.') ?: '0';
        $this->min_stock = rtrim(rtrim((string) $record->min_stock, '0'), '.');
        $this->is_active = $record->is_active;
        $this->outletPrices = ProductOutletPrice::query()->where('product_id', $record->id)->pluck('price', 'outlet_id')->map(fn ($price) => (string) $price)->all();
        $this->outletMinStocks = ProductStock::query()->where('product_id', $record->id)->whereNotNull('min_stock')->pluck('min_stock', 'outlet_id')->map(fn ($min) => rtrim(rtrim((string) $min, '0'), '.'))->all();
        $this->currentImageUrl = $record->imageUrl();
        $this->custom_attributes = $record->custom_attributes ?? [];
        $this->drug_class = (string) $record->drug_class;
        $this->requires_prescription = $record->requires_prescription;
        $this->track_batch = $record->track_batch;
        $this->units = $record->units()->get()->map(fn (ProductUnit $unit) => [
            'id' => $unit->id,
            'name' => $unit->name,
            'factor' => rtrim(rtrim((string) $unit->factor, '0'), '.'),
            'price' => $unit->price === null ? '' : (string) $unit->price,
            'barcode' => (string) $unit->barcode,
            'is_default_sale' => $unit->is_default_sale,
        ])->all();
        $this->price_tiers = $record->priceTiers()->get()->map(fn ($tier) => [
            'min_quantity' => rtrim(rtrim((string) $tier->min_quantity, '0'), '.'),
            'price' => (string) $tier->price,
        ])->all();
        $this->variant_options = collect($record->variant_options ?? [])->map(fn (array $option) => ['name' => (string) $option['name'], 'values' => implode(', ', $option['values'] ?? [])])->all();
        $this->track_serial = $record->track_serial;
        $this->warranty_days = $record->warranty_days === null ? '' : (string) $record->warranty_days;
        $this->modifier_group_ids = $record->modifierGroups()->pluck('modifier_groups.id')->map(fn ($id) => (string) $id)->all();
        $this->components = $record->components()->get()->map(fn ($component) => [
            'component_id' => (string) $component->component_id,
            'quantity' => rtrim(rtrim((string) $component->quantity, '0'), '.'),
        ])->all();
    }

    protected function rules(): array
    {
        return [
            'category_id' => ['nullable', TenantRule::exists('categories', 'id')],
            'sku' => ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9._\-\/]+$/', TenantRule::unique('products', 'sku')->ignore($this->editingId)],
            'barcode' => ['nullable', 'string', 'max:64', TenantRule::unique('products', 'barcode')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:150'],
            'unit' => ['required', 'string', 'max:20'],
            'cost_price' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'price' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'track_stock' => ['boolean'],
            'stock' => [$this->editingId ? 'nullable' : 'required', 'numeric', 'min:0', 'max:99999999'],
            'min_stock' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'is_active' => ['boolean'],
            'outletPrices.*' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'outletMinStocks.*' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'image' => ['nullable', 'image', 'max:2048'],
            'initial_batch_number' => ['nullable', 'string', 'max:50'],
            'initial_expires_at' => ['nullable', 'date'],
            ...ProductCapabilityData::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'sku.unique' => 'SKU ini sudah dipakai produk lain.',
            'sku.regex' => 'SKU hanya boleh huruf, angka, titik, garis bawah, strip, dan garis miring.',
            'barcode.unique' => 'Barcode ini sudah dipakai produk lain.',
            'price.required' => 'Harga jual wajib diisi.',
            ...ProductCapabilityData::messages(),
        ];
    }
}
