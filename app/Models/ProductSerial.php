<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu unit barang bernomor seri/IMEI. Jumlah unit in_stock per (produk, outlet) mengikuti stok outlet;
 * StockService menjaganya lewat stok masuk, penjualan, pembatalan, dan transfer.
 */
class ProductSerial extends Model
{
    use BelongsToTenant;

    public const IN_STOCK = 'in_stock';

    public const SOLD = 'sold';

    public const REMOVED = 'removed';

    protected $fillable = ['product_id', 'outlet_id', 'serial', 'status', 'sale_item_id', 'sold_at'];

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

    /**
     * @return BelongsTo<SaleItem, $this>
     */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    /**
     * @param  Builder<ProductSerial>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('status', self::IN_STOCK);
    }

    protected function casts(): array
    {
        return ['sold_at' => 'datetime'];
    }
}
