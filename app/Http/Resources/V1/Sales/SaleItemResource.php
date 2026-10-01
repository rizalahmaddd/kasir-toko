<?php

namespace App\Http\Resources\V1\Sales;

use App\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris barang di transaksi. Nama, SKU, harga, dan HPP disalin saat transaksi, jadi tidak ikut
 * berubah walau data produknya diubah atau dihapus.
 *
 * @mixin SaleItem
 */
class SaleItemResource extends JsonResource
{
    /**
     * @return array{id: int, product_id: int|null, product_name: string, sku: string|null, unit: string, quantity: numeric-string, price: int, discount_amount: int, total: int, note: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'sku' => $this->sku,
            'unit' => $this->unit,
            'quantity' => $this->quantity,
            'price' => $this->price,
            'discount_amount' => $this->discount_amount,
            'total' => $this->total,
            'note' => $this->note,
        ];
    }
}
