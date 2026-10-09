<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItemBatch extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['sale_item_id', 'product_batch_id', 'quantity'];

    /**
     * @return BelongsTo<ProductBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }
}
