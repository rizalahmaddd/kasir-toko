<?php

namespace App\Models;

use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\StockCountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Dokumen stok opname per outlet. Stok tidak berubah sampai dokumen diselesaikan, dan saat itu
 * stok ditambah/dikurangi sebesar selisih per barang (bukan di-set ke hasil hitung).
 */
class StockCount extends Model
{
    use Auditable;
    use BelongsToOutlet;
    use BelongsToTenant;

    /** @use HasFactory<StockCountFactory> */
    use HasFactory;

    public const UNCOUNTED_KEEP = 'keep';

    public const UNCOUNTED_ZERO = 'zero';

    protected $fillable = [
        'outlet_id',
        'number',
        'status',
        'scope',
        'scope_category_ids',
        'blind_count',
        'hold_adjustments',
        'uncounted_policy',
        'note',
        'created_by',
        'submitted_by',
        'posted_by',
        'cancelled_by',
        'started_at',
        'submitted_at',
        'posted_at',
        'cancelled_at',
        'cancel_reason',
        'summary',
    ];

    /**
     * @return HasMany<StockCountItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }

    /**
     * @return HasManyThrough<StockCountEntry, StockCountItem, $this>
     */
    public function entries(): HasManyThrough
    {
        return $this->hasManyThrough(StockCountEntry::class, StockCountItem::class);
    }

    /**
     * @return HasMany<StockCountSerial, $this>
     */
    public function serials(): HasMany
    {
        return $this->hasMany(StockCountSerial::class);
    }

    /**
     * @return HasMany<StockCountUnknownItem, $this>
     */
    public function unknownItems(): HasMany
    {
        return $this->hasMany(StockCountUnknownItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * @param  Builder<StockCount>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', array_map(fn (StockCountStatus $status) => $status->value, StockCountStatus::open()));
    }

    protected function casts(): array
    {
        return [
            'status' => StockCountStatus::class,
            'scope' => StockCountScope::class,
            'scope_category_ids' => 'array',
            'blind_count' => 'boolean',
            'hold_adjustments' => 'boolean',
            'summary' => 'array',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
