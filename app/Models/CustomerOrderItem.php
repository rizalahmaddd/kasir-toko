<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOrderItem extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['customer_order_id', 'product_id', 'name', 'quantity', 'price', 'note'];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'price' => 'integer'];
    }
}
