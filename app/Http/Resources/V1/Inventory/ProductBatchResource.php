<?php

namespace App\Http\Resources\V1\Inventory;

use App\Models\ProductBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Batch stok satu produk di satu outlet. `quantity` string desimal dalam satuan dasar produk, boleh
 * negatif untuk batch tanpa nomor saat stok minus diizinkan. `days_left` negatif berarti sudah lewat.
 *
 * @mixin ProductBatch
 */
class ProductBatchResource extends JsonResource
{
    /**
     * @return array{id: int, product_id: int, product_name: string|null, unit: string|null, batch_number: string|null, expires_at: string|null, days_left: int|null, is_expired: bool, quantity: numeric-string, unit_cost: int|null, source: string, received_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product?->name),
            'unit' => $this->whenLoaded('product', fn () => $this->product?->unit),
            'batch_number' => $this->batch_number,
            'expires_at' => $this->expires_at?->toDateString(),
            'days_left' => $this->expires_at ? (int) today()->diffInDays($this->expires_at, false) : null,
            'is_expired' => $this->isExpired(),
            'quantity' => number_format((float) $this->quantity, 3, '.', ''),
            'unit_cost' => $this->unit_cost,
            'source' => $this->source->value,
            'received_at' => $this->received_at->toIso8601String(),
        ];
    }
}
