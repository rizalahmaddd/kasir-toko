<?php

namespace App\Http\Resources\V1\MasterData;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Produk. Harga dalam rupiah bulat; `stock` dan `min_stock` string desimal (3 digit) dan hanya
 * bermakna kalau `track_stock` true.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array{id: int, sku: string, barcode: string|null, name: string, unit: string, category: CategoryResource|null, cost_price: int, price: int, track_stock: bool, stock: numeric-string, min_stock: numeric-string, is_low_stock: bool, image_url: string|null, is_active: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'unit' => $this->unit,
            'category' => $this->category ? new CategoryResource($this->category) : null,
            'cost_price' => $this->cost_price,
            'price' => $this->price,
            'track_stock' => $this->track_stock,
            'stock' => $this->stock,
            'min_stock' => $this->min_stock,
            'is_low_stock' => $this->isLowStock(),
            'image_url' => $this->imageUrl(),
            'is_active' => $this->is_active,
        ];
    }
}
