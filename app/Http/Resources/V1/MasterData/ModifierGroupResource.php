<?php

namespace App\Http\Resources\V1\MasterData;

use App\Models\Modifier;
use App\Models\ModifierGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Grup pilihan tambahan. `min_select` > 0 berarti wajib dipilih di kasir, `max_select` null berarti bebas.
 * Pilihan dengan `product_id` memotong stok bahan itu sebanyak `ingredient_quantity` per porsi.
 *
 * @mixin ModifierGroup
 */
class ModifierGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'min_select' => $this->min_select,
            'max_select' => $this->max_select,
            'rule' => $this->ruleLabel(),
            'is_active' => $this->is_active,
            'products_count' => $this->whenCounted('products'),
            'options' => $this->whenLoaded('modifiers', fn () => $this->modifiers->map(fn (Modifier $modifier) => [
                'id' => $modifier->id,
                'name' => $modifier->name,
                'price' => $modifier->price,
                'product_id' => $modifier->product_id,
                'ingredient_quantity' => $modifier->ingredient_quantity === null ? null : number_format((float) $modifier->ingredient_quantity, 3, '.', ''),
                'is_active' => $modifier->is_active,
            ])->values()->all()),
            'products' => $this->whenLoaded('products', fn () => $this->products->map(fn ($product) => ['id' => $product->id, 'name' => $product->name])->values()->all()),
        ];
    }
}
