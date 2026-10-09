<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stok satu produk di satu outlet. products.stock adalah total semua outlet dan hanya boleh
 * diubah bersama baris ini lewat App\Services\Pos\StockService.
 */
class ProductStock extends Model
{
    use BelongsToTenant;

    protected $fillable = ['product_id', 'outlet_id', 'stock', 'min_stock'];

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
        return [
            'stock' => 'decimal:3',
            'min_stock' => 'decimal:3',
        ];
    }
}
