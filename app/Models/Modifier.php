<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu pilihan tambahan dengan harga tambahan per satuan jual. Bila product_id diisi, setiap pilihan yang
 * terjual memotong stok bahan itu sebanyak ingredient_quantity (satuan dasar bahan).
 */
class Modifier extends Model
{
    use Auditable;
    use BelongsToTenant;
    use SoftDeletes;

    protected $fillable = ['modifier_group_id', 'name', 'price', 'product_id', 'ingredient_quantity', 'sort_order', 'is_active'];

    /**
     * @return BelongsTo<ModifierGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }

    public function usesIngredient(): bool
    {
        return $this->product_id !== null && (float) $this->ingredient_quantity > 0;
    }

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'ingredient_quantity' => 'decimal:3',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
