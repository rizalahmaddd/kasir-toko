<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'product_id',
        'product_name',
        'sku',
        'unit',
        'quantity',
        'price',
        'cost_price',
        'discount_amount',
        'total',
        'note',
    ];

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
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
        return [
            'quantity' => 'decimal:3',
            'price' => 'integer',
            'cost_price' => 'integer',
            'discount_amount' => 'integer',
            'total' => 'integer',
        ];
    }
}
