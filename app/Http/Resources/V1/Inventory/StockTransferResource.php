<?php

namespace App\Http\Resources\V1\Inventory;

use App\Http\Resources\V1\UserSummaryResource;
use App\Models\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pemindahan stok antar outlet. Berlaku seketika: `status` `completed` berarti stok sudah berpindah,
 * `cancelled` berarti sudah dikembalikan. `items` hanya ada di detail.
 *
 * @mixin StockTransfer
 */
class StockTransferResource extends JsonResource
{
    /**
     * @return array{id: int, number: string, status: string, from_outlet: array{id: int, name: string, code: string}|null, to_outlet: array{id: int, name: string, code: string}|null, note: string|null, transferred_at: string, cancelled_at: string|null, created_by: UserSummaryResource|null, items_count?: int, items?: list<array{product_id: int, name: string|null, unit: string|null, quantity: numeric-string}>}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'from_outlet' => $this->fromOutlet ? ['id' => $this->fromOutlet->id, 'name' => $this->fromOutlet->name, 'code' => $this->fromOutlet->code] : null,
            'to_outlet' => $this->toOutlet ? ['id' => $this->toOutlet->id, 'name' => $this->toOutlet->name, 'code' => $this->toOutlet->code] : null,
            'note' => $this->note,
            'transferred_at' => $this->transferred_at->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_by' => $this->creator ? new UserSummaryResource($this->creator) : null,
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'name' => $item->product?->name,
                'unit' => $item->product?->unit,
                'quantity' => $item->quantity,
            ])->all()),
        ];
    }
}
