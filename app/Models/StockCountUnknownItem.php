<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Barcode yang di-scan saat opname tapi tidak dikenal; dicatat untuk dibuatkan produk nanti, tidak memengaruhi stok.
 */
class StockCountUnknownItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['stock_count_id', 'barcode', 'quantity', 'note', 'user_id'];

    /**
     * @return BelongsTo<StockCount, $this>
     */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
        ];
    }
}
