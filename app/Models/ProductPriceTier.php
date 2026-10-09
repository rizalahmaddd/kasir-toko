<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Harga grosir: mulai min_quantity (satuan dasar) harga satuan dasar menjadi price.
 */
class ProductPriceTier extends Model
{
    use BelongsToTenant;

    protected $fillable = ['product_id', 'min_quantity', 'price'];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:3',
            'price' => 'integer',
        ];
    }
}
