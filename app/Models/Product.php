<?php

namespace App\Models;

use App\Events\ProductChanged;
use App\Models\Concerns\Auditable;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use Auditable;

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
                ->orWhere('barcode', $term);
        });
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopeLowStock(Builder $query): void
    {
        $query->where('track_stock', true)->whereColumn('stock', '<=', 'min_stock');
    }

    public function isLowStock(): bool
    {
        return $this->track_stock && (float) $this->stock <= (float) $this->min_stock;
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    protected function casts(): array
    {
        return [
            'cost_price' => 'integer',
            'price' => 'integer',
            'track_stock' => 'boolean',
            'stock' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }
}
