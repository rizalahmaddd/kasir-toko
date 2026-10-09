<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\StockCountEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu input hitungan. Entri yang salah dibatalkan lewat voided_at, tidak dihapus, supaya setiap
 * angka di dokumen tetap punya jejak siapa dan kapan.
 */
class StockCountEntry extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<StockCountEntryFactory> */
    use HasFactory;

    public const SOURCE_WEB = 'web';

    public const SOURCE_MOBILE = 'mobile';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_SCAN = 'scan';

    public const SOURCE_POLICY = 'policy';

    protected $fillable = [
        'stock_count_item_id',
        'user_id',
        'client_uuid',
        'product_batch_id',
        'new_batch_number',
        'new_batch_expires_at',
        'batch_system_qty',
        'product_unit_id',
        'breakdown',
        'quantity_base',
        'system_qty_at_count',
        'counted_at',
        'source',
        'note',
        'voided_at',
        'voided_by',
    ];

    /**
     * @return BelongsTo<StockCountItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockCountItem::class, 'stock_count_item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * @return BelongsTo<ProductBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    /**
     * @return BelongsTo<ProductUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * @param  Builder<StockCountEntry>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    protected function casts(): array
    {
        return [
            'new_batch_expires_at' => 'date',
            'breakdown' => 'array',
            'quantity_base' => 'decimal:3',
            'system_qty_at_count' => 'decimal:3',
            'batch_system_qty' => 'decimal:3',
            'counted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
