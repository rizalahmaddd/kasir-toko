<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bahan penyusun produk racikan: tiap satu satuan dasar produk memakai $quantity satuan dasar bahan.
 */
class ProductComponent extends Model
{
    use BelongsToTenant;

    protected $fillable = ['product_id', 'component_id', 'quantity'];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }
}
