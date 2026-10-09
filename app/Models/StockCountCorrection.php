<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Koreksi susulan: penjualan offline yang terjadi sebelum barang dihitung tapi baru masuk setelah opname diselesaikan.
 */
class StockCountCorrection extends Model
{
    use BelongsToTenant;

    protected $fillable = ['stock_count_item_id', 'sale_id', 'stock_movement_id', 'quantity'];

    /**
     * @return BelongsTo<StockCountItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockCountItem::class, 'stock_count_item_id');
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
        ];
    }
}
