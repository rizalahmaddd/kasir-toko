<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stok bahan yang ikut terpotong oleh satu baris penjualan (bahan modifier atau komposisi racikan),
 * supaya pembatalan transaksi bisa mengembalikannya ke batch asal.
 */
class SaleItemComponent extends Model
{
    use BelongsToTenant;

    public const SOURCE_MODIFIER = 'modifier';

    public const SOURCE_RECIPE = 'recipe';

    public $timestamps = false;

    protected $fillable = ['sale_item_id', 'product_id', 'stock_movement_id', 'quantity', 'source'];

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }
}
