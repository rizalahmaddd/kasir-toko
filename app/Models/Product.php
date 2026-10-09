<?php

namespace App\Models;

use App\Enums\DrugClass;
use App\Events\ProductChanged;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\StorePresets;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'category_id',
        'sku',
        'barcode',
        'name',
        'unit',
        'cost_price',
        'price',
        'track_stock',
        'stock',
        'min_stock',
        'image_path',
        'is_active',
        'custom_attributes',
        'attributes_search',
        'drug_class',
        'requires_prescription',
        'track_batch',
        'parent_id',
        'variant_options',
        'variant_values',
        'track_serial',
        'warranty_days',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'saved' => ProductChanged::class,
        'deleted' => ProductChanged::class,
    ];

    /**
     * A deleted product must not hold on to its barcode: the replacement product is usually
     * scanned with the same one. Sale items keep their own copy of the product data.
     */
    protected static function booted(): void
    {
        // Baris stok dibuat untuk semua outlet sejak produk ada; stok awal (kalau ada) ikut outlet utama.
        static::created(function (Product $product) {
            $outlets = Outlet::query()->withoutGlobalScopes()->where('tenant_id', $product->tenant_id)->get(['id', 'is_primary']);

            if ($outlets->isNotEmpty()) {
                ProductStock::query()->insert($outlets->map(fn (Outlet $outlet) => [
                    'tenant_id' => $product->tenant_id,
                    'product_id' => $product->id,
                    'outlet_id' => $outlet->id,
                    'stock' => $outlet->is_primary ? (float) ($product->getAttributes()['stock'] ?? 0) : 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            }
        });

        static::saving(function (Product $product) {
            if ($product->isDirty('custom_attributes')) {
                $product->attributes_search = self::searchTextFor($product->custom_attributes ?? []);
            }
        });

        static::softDeleted(function (Product $product) {
            if ($product->barcode !== null) {
                $product->forceFill(['barcode' => null])->saveQuietly();
            }
        });
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * @return HasMany<ProductUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->orderBy('sort_order')->orderBy('factor');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_id')->withTrashed();
    }

    /**
     * SKU anak varian; produk induk tidak dijual langsung.
     *
     * @return HasMany<Product, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_id')->orderBy('name');
    }

    /**
     * @return HasMany<ProductSerial, $this>
     */
    public function serials(): HasMany
    {
        return $this->hasMany(ProductSerial::class);
    }

    public function isVariantParent(): bool
    {
        return $this->variant_options !== null && $this->variant_options !== [];
    }

    /**
     * Nomor seri dicatat untuk produk ini (kapabilitas menyala, stok dilacak).
     */
    public function tracksSerials(): bool
    {
        return $this->track_stock && $this->track_serial && Features::enabled('business.serial-number');
    }

    /**
     * Label kombinasi varian, mis. "M / Merah".
     */
    public function variantLabel(): ?string
    {
        $values = array_filter(array_map(fn ($value) => is_scalar($value) ? (string) $value : null, $this->variant_values ?? []));

        return $values === [] ? null : implode(' / ', $values);
    }

    /**
     * @return BelongsToMany<ModifierGroup, $this>
     */
    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class)->withPivot('sort_order')->orderByPivot('sort_order');
    }

    /**
     * @return HasMany<ProductPriceTier, $this>
     */
    public function priceTiers(): HasMany
    {
        return $this->hasMany(ProductPriceTier::class)->orderBy('min_quantity');
    }

    /**
     * @return HasMany<ProductComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(ProductComponent::class);
    }

    /**
     * @return HasMany<ProductBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    /**
     * Nilai atribut yang ditandai bisa dicari, digabung jadi satu kolom supaya pencarian tidak perlu JSON_SEARCH.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function searchTextFor(array $attributes): ?string
    {
        $text = collect(StorePresets::searchableAttributeKeys())
            ->map(fn (string $key) => is_scalar($attributes[$key] ?? null) ? trim((string) $attributes[$key]) : '')
            ->filter()
            ->implode(' | ');

        return $text === '' ? null : mb_substr($text, 0, 255);
    }

    public function drugClass(): ?DrugClass
    {
        return DrugClass::tryFrom((string) $this->drug_class);
    }

    /**
     * Batch & kedaluwarsa dicatat untuk produk ini (kapabilitas menyala, produk dilacak stok & batch-nya).
     */
    public function tracksBatches(): bool
    {
        return $this->track_stock && $this->track_batch && Features::enabled('business.batch-expiry');
    }

    /**
     * @return HasMany<ProductStock, $this>
     */
    public function outletStocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    /**
     * @return HasMany<ProductOutletPrice, $this>
     */
    public function outletPrices(): HasMany
    {
        return $this->hasMany(ProductOutletPrice::class);
    }

    /**
     * Tambahkan stok, stok minimum, dan harga milik satu outlet sebagai kolom outlet_stock,
     * outlet_min_stock, outlet_price, dan has_outlet_price. Memakai subquery supaya nama kolom
     * di where/order query pemanggil tidak jadi ambigu.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeWithOutletData(Builder $query, ?int $outletId = null): void
    {
        $outletId ??= app(CurrentOutlet::class)->idOrPrimary() ?? 0;

        $query->addSelect('products.*')->selectRaw(
            'COALESCE('.self::outletStockSql('stock').', 0) as outlet_stock, '
            .'COALESCE('.self::outletStockSql('min_stock').', products.min_stock) as outlet_min_stock, '
            .'COALESCE('.self::outletPriceSql().', products.price) as outlet_price, '
            .'('.self::outletPriceSql().' IS NOT NULL) as has_outlet_price',
            [$outletId, $outletId, $outletId, $outletId],
        );
    }

    /**
     * Produk yang dijual di outlet: tanpa kategori, atau kategorinya tidak dibatasi ke outlet lain.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeAvailableAt(Builder $query, ?int $outletId = null): void
    {
        $outletId ??= app(CurrentOutlet::class)->idOrPrimary() ?? 0;

        $query->where(fn (Builder $query) => $query
            ->whereNull('products.category_id')
            ->orWhereHas('category', fn (Builder $category) => $category->availableAt($outletId)));
    }

    /**
     * Produk yang boleh dijual kasir outlet: tersedia di outlet, dan tidak bergantung pada fitur yang
     * dimatikan di outlet itu (obat wajib resep, barang ber-IMEI, paket/racikan). Kasir dan checkout
     * memakai aturan yang sama supaya tidak ada produk yang tampil tapi ditolak saat bayar.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeSellableAt(Builder $query, ?int $outletId = null): void
    {
        $outletId ??= app(CurrentOutlet::class)->idOrPrimary() ?? 0;
        $offAtOutlet = fn (string $key): bool => Features::enabled($key) && ! Features::enabledAt($key, $outletId);

        $query->availableAt($outletId)
            ->when($offAtOutlet('business.prescription'), fn (Builder $query) => $query->where('products.requires_prescription', false))
            ->when($offAtOutlet('business.serial-number'), fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('products.track_serial', false)->orWhere('products.track_stock', false)))
            ->when($offAtOutlet('business.components'), fn (Builder $query) => $query->whereDoesntHave('components'));
    }

    /**
     * Muat stok, stok minimum, dan harga outlet ke model yang sudah ada (mis. hasil route binding),
     * supaya outletStock() dan effectivePrice() tidak jatuh ke nilai total/bawaan.
     */
    public function loadOutletData(?int $outletId = null): static
    {
        $fresh = static::query()->withTrashed()->withOutletData($outletId)->whereKey($this->id)->first();

        if ($fresh) {
            $extra = array_intersect_key($fresh->getAttributes(), array_flip(['outlet_stock', 'outlet_min_stock', 'outlet_price', 'has_outlet_price']));
            $this->setRawAttributes([...$this->getAttributes(), ...$extra], true);
        }

        return $this;
    }

    private static function outletStockSql(string $column): string
    {
        return "(select ps.{$column} from product_stocks ps where ps.product_id = products.id and ps.outlet_id = ?)";
    }

    private static function outletPriceSql(): string
    {
        return '(select pp.price from product_outlet_prices pp where pp.product_id = products.id and pp.outlet_id = ?)';
    }

    /**
     * Harga jual yang berlaku di outlet: harga khusus outlet, atau harga bawaan produk.
     */
    public function priceAt(?int $outletId = null): int
    {
        $outletId ??= app(CurrentOutlet::class)->idOrPrimary();

        $override = $outletId === null ? null : ProductOutletPrice::query()->where('product_id', $this->id)->where('outlet_id', $outletId)->value('price');

        return (int) ($override ?? $this->price);
    }

    /**
     * Harga efektif outlet dari scopeWithOutletData(); tanpa itu, harga bawaan produk.
     */
    public function effectivePrice(): int
    {
        return (int) ($this->getAttributes()['outlet_price'] ?? $this->price);
    }

    public function hasOutletPrice(): bool
    {
        return (bool) ($this->getAttributes()['has_outlet_price'] ?? false);
    }

    /**
     * Stok outlet dari scopeWithOutletData(); tanpa itu, stok total (sama untuk toko satu outlet).
     */
    public function outletStock(): float
    {
        return (float) ($this->getAttributes()['outlet_stock'] ?? $this->stock);
    }

    public function outletMinStock(): float
    {
        return (float) ($this->getAttributes()['outlet_min_stock'] ?? $this->min_stock);
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term) {
            $query->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', $term)
                ->orWhere('attributes_search', 'like', "%{$term}%")
                ->orWhereHas('units', fn (Builder $query) => $query->where('barcode', $term));
        });
    }

    /**
     * Stok menipis di satu outlet (default outlet aktif), memakai stok minimum outlet kalau diisi.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeLowStock(Builder $query, ?int $outletId = null): void
    {
        $outletId ??= app(CurrentOutlet::class)->idOrPrimary() ?? 0;

        $query->where('products.track_stock', true)->whereRaw(
            'COALESCE('.self::outletStockSql('stock').', 0) <= COALESCE('.self::outletStockSql('min_stock').', products.min_stock)',
            [$outletId, $outletId],
        );
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopeOutOfStock(Builder $query, ?int $outletId = null): void
    {
        $outletId ??= app(CurrentOutlet::class)->idOrPrimary() ?? 0;

        $query->where('products.track_stock', true)->whereRaw('COALESCE('.self::outletStockSql('stock').', 0) <= 0', [$outletId]);
    }

    /**
     * Bandingkan stok outlet dengan angka tertentu, mis. whereOutletStock('>', 0).
     *
     * @param  Builder<Product>  $query
     */
    public function scopeWhereOutletStock(Builder $query, string $operator, float $value, ?int $outletId = null): void
    {
        $outletId ??= app(CurrentOutlet::class)->idOrPrimary() ?? 0;
        abort_unless(in_array($operator, ['>', '>=', '<', '<=', '='], true), 500);

        // Angka ditulis sebagai literal: PDO mengikat float sebagai teks, dan SQLite menganggap teks lebih besar dari angka.
        $query->whereRaw('COALESCE('.self::outletStockSql('stock').', 0) '.$operator.' '.sprintf('%.3F', $value), [$outletId]);
    }

    public function isLowStock(): bool
    {
        return $this->track_stock && $this->outletStock() <= $this->outletMinStock();
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    protected function casts(): array
    {
        return [
            'variant_options' => 'array',
            'variant_values' => 'array',
            'track_serial' => 'boolean',
            'warranty_days' => 'integer',
            'cost_price' => 'integer',
            'price' => 'integer',
            'track_stock' => 'boolean',
            'stock' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'is_active' => 'boolean',
            'custom_attributes' => 'array',
            'requires_prescription' => 'boolean',
            'track_batch' => 'boolean',
        ];
    }
}
