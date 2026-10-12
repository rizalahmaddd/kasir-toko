<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot satu baris pesanan QR saat pelanggan submit. Harga dan pilihan tambahan di sini yang ditagihkan,
 * walau katalog berubah sebelum pesanan dibayar.
 */
class SelfOrderItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['self_order_id', 'product_id', 'product_name', 'unit', 'price', 'quantity', 'modifiers', 'modifiers_total', 'discount_amount', 'total', 'note'];

    /**
     * @return BelongsTo<SelfOrder, $this>
     */
    public function selfOrder(): BelongsTo
    {
        return $this->belongsTo(SelfOrder::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Baris keranjang kasir (bentuk pos.js / HeldOrder.cart) untuk item ini.
     *
     * @return array<string, mixed>
     */
    public function toCartItem(): array
    {
        return [
            'product_id' => $this->product_id,
            'name' => $this->product_name,
            'unit' => (string) $this->unit,
            'price' => $this->price,
            'quantity' => (float) $this->quantity,
            'discount' => 0,
            'auto_discount' => $this->discount_amount,
            'note' => $this->note,
            'modifiers' => $this->modifiers ?? [],
            'self_order_item_id' => $this->id,
        ];
    }

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'quantity' => 'float',
            'modifiers' => 'array',
            'modifiers_total' => 'integer',
            'discount_amount' => 'integer',
            'total' => 'integer',
        ];
    }
}
