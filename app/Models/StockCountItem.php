<?php

namespace App\Models;

use App\Enums\StockCountReason;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\StockCountItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCountItem extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<StockCountItemFactory> */
    use HasFactory;

    protected $fillable = [
        'stock_count_id',
        'product_id',
        'expected_qty',
        'counted_qty',
        'reference_at',
        'reference_system_qty',
        'variance_qty',
        'unit_cost',
        'reason',
        'needs_recount',
        'flags',
        'stock_movement_id',
    ];

    /**
     * @return BelongsTo<StockCount, $this>
     */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return HasMany<StockCountEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(StockCountEntry::class);
    }

    /**
     * @return HasMany<StockCountEntry, $this>
     */
    public function activeEntries(): HasMany
    {
        return $this->entries()->whereNull('voided_at');
    }

    /**
     * @return HasMany<StockCountCorrection, $this>
     */
    public function corrections(): HasMany
    {
        return $this->hasMany(StockCountCorrection::class);
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function isCounted(): bool
    {
        return $this->counted_qty !== null;
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags ?? [], true);
    }

    protected function casts(): array
    {
        return [
            'expected_qty' => 'decimal:3',
            'counted_qty' => 'decimal:3',
            'reference_at' => 'datetime',
            'reference_system_qty' => 'decimal:3',
            'variance_qty' => 'decimal:3',
            'unit_cost' => 'integer',
            'reason' => StockCountReason::class,
            'needs_recount' => 'boolean',
            'flags' => 'array',
        ];
    }
}
