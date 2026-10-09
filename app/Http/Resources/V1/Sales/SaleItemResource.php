<?php

namespace App\Http\Resources\V1\Sales;

use App\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris barang di transaksi. Nama, SKU, harga, dan HPP disalin saat transaksi, jadi tidak ikut
 * berubah walau data produknya diubah atau dihapus. `unit` adalah satuan yang dijual; `base_quantity` jumlahnya
 * dalam satuan dasar produk (quantity × unit_factor). `modifiers` snapshot pilihan tambahan; total baris =
 * round((price + modifiers_total) × quantity) - discount_amount.
 *
 * @mixin SaleItem
 */
class SaleItemResource extends JsonResource
{
    /**
     * @return array{id: int, product_id: int|null, product_name: string, sku: string|null, unit: string, quantity: numeric-string, price: int, discount_amount: int, total: int, note: string|null, product_unit_id: int|null, unit_factor: numeric-string, base_quantity: numeric-string, modifiers: list<array{id: int, name: string, price: int}>, modifiers_total: int, serials: list<string>}
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
            'product_unit_id' => $this->product_unit_id,
            'unit_factor' => number_format((float) ($this->unit_factor ?: 1), 3, '.', ''),
            'base_quantity' => number_format($this->baseQuantity(), 3, '.', ''),
            'modifiers' => array_values(array_map(fn (array $modifier) => ['id' => (int) ($modifier['id'] ?? 0), 'name' => (string) ($modifier['name'] ?? ''), 'price' => (int) ($modifier['price'] ?? 0)], array_filter($this->modifiers ?? [], 'is_array'))),
            'modifiers_total' => (int) $this->modifiers_total,
            'serials' => $this->relationLoaded('serials') ? $this->serials->pluck('serial')->values()->all() : [],
        ];
    }
}
