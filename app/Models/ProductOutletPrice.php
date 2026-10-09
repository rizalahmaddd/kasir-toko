<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Harga jual khusus outlet. Produk tanpa baris di sini memakai products.price.
 */
class ProductOutletPrice extends Model
{
    use BelongsToTenant;

    protected $fillable = ['product_id', 'outlet_id', 'price'];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    protected function casts(): array
    {
        return ['price' => 'integer'];
    }
}
