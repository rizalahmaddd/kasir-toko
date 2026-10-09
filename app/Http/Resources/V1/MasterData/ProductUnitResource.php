<?php

namespace App\Http\Resources\V1\MasterData;

use App\Models\ProductUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satuan jual tambahan. `factor` = berapa satuan dasar produk dalam satu satuan ini (string desimal).
 * `price` adalah harga jual satuan ini di outlet aktif; `fixed_price` harga tetap yang diisi pemilik,
 * null berarti dihitung dari faktor × harga satuan dasar outlet.
 *
 * @mixin ProductUnit
 */
class ProductUnitResource extends JsonResource
{
    public function __construct(ProductUnit $resource, private int $basePrice = 0)
    {
        parent::__construct($resource);
    }

    /**
     * @return array{id: int, name: string, factor: numeric-string, price: int, fixed_price: int|null, barcode: string|null, is_default_sale: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'factor' => number_format((float) $this->factor, 3, '.', ''),
            'price' => $this->priceFrom($this->basePrice),
            'fixed_price' => $this->price,
            'barcode' => $this->barcode,
            'is_default_sale' => $this->is_default_sale,
        ];
    }
}
