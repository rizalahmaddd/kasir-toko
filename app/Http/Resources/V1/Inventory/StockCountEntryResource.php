<?php

namespace App\Http\Resources\V1\Inventory;

use App\Http\Resources\V1\UserSummaryResource;
use App\Models\StockCountEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu input hitungan (satuan dasar). Entri yang dibatalkan tetap ada dengan `voided_at` terisi.
 *
 * @mixin StockCountEntry
 */
class StockCountEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'item_id' => $this->stock_count_item_id,
            'quantity' => (float) $this->quantity_base,
            'unit_id' => $this->product_unit_id,
            'breakdown' => $this->breakdown,
            'product_batch_id' => $this->product_batch_id,
            'new_batch_number' => $this->new_batch_number,
            'source' => $this->source,
            'note' => $this->note,
            'counted_at' => $this->counted_at->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'user' => $this->user ? new UserSummaryResource($this->user) : null,
        ];
    }
}
