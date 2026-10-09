<?php

namespace App\Http\Resources\V1\MasterData;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kategori produk. `products_count` hanya ada di daftar kategori. `outlet_ids` kosong berarti dijual di
 * semua outlet; berisi id berarti hanya tampil di kasir outlet tersebut.
 *
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, sort_order: int, is_active: bool, products_count?: int, outlet_ids?: list<int>}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'products_count' => $this->whenCounted('products'),
            'outlet_ids' => $this->whenLoaded('outlets', fn () => $this->outlets->pluck('id')->map(fn ($id) => (int) $id)->values()->all()),
        ];
    }
}
