<?php

namespace App\Livewire\MasterData;

use App\Enums\StockMovementType;
use App\Livewire\Concerns\WithCrudActions;
use App\Livewire\Concerns\WithRealtimeRefresh;
use App\Models\Category;
use App\Models\Product;
use App\Services\DocumentNumberGenerator;
use App\Services\Pos\StockService;
use App\Services\ProductImportService;
use App\Support\CurrentTenant;
use App\Support\NumberFormatter;
use App\Support\PlanLimits;
use App\Support\TenantRule;
use Illuminate\Database\Eloquent\Builder;
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

    public function save(DocumentNumberGenerator $numbers, StockService $stockService): void
    {
        $this->authorizeManage();

        $this->sku = strtoupper(trim($this->sku));
        $this->barcode = trim($this->barcode);
        $this->min_stock = str_replace(',', '.', trim($this->min_stock));
        if (! $this->editingId) {
            $this->stock = str_replace(',', '.', trim($this->stock));
        }
        $this->price = preg_replace('/\D/', '', $this->price) ?? '';
        $this->cost_price = preg_replace('/\D/', '', $this->cost_price) ?? '';
        $validated = $this->validate();
        $isEditing = (bool) $this->editingId;

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

        DB::transaction(function () use ($isEditing, $attributes, $validated, $stockService) {
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

            $product->save();

            $initialStock = (float) ($validated['stock'] ?? 0);
            if (! $isEditing && $product->track_stock && $initialStock != 0.0) {
                $stockService->move($product, StockMovementType::Initial, $initialStock, auth()->user(), null, 'Stok awal saat produk dibuat', $product->cost_price);
            }
        });

        $this->closeModal();
        $this->notify($isEditing ? 'Produk diperbarui.' : 'Produk ditambahkan.');
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
            NumberFormatter::currency($product->price),
            $product->track_stock ? NumberFormatter::quantity($product->stock) : 'Tidak dilacak',
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
            ->search($this->search)
            ->when($this->categoryFilter === 'none', fn (Builder $query) => $query->whereNull('category_id'))
            ->when(ctype_digit($this->categoryFilter), fn (Builder $query) => $query->where('category_id', (int) $this->categoryFilter))
            ->when($this->statusFilter === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($this->statusFilter === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->when($this->statusFilter === 'low', fn (Builder $query) => $query->lowStock());

        $this->applySorting($query, [
            'sku' => 'sku',
            'name' => 'name',
            'price' => 'price',
            'cost_price' => 'cost_price',
            'stock' => 'stock',
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
        $this->reset(['category_id', 'sku', 'barcode', 'name', 'cost_price', 'price', 'image', 'currentImageUrl', 'removeImage']);
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
        $this->stock = rtrim(rtrim((string) $record->stock, '0'), '.');
        $this->min_stock = rtrim(rtrim((string) $record->min_stock, '0'), '.');
        $this->is_active = $record->is_active;
        $this->currentImageUrl = $record->imageUrl();
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
            'image' => ['nullable', 'image', 'max:2048'],
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
        ];
    }
}
