<?php

namespace App\Http\Resources\V1\Inventory;

use App\Http\Resources\V1\UserSummaryResource;
use App\Models\Sale;
use App\Models\StockCount;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris kartu stok. `quantity` bertanda: positif menambah, negatif mengurangi. `sale_id`
 * terisi untuk mutasi dari penjualan atau pembatalannya, `stock_count_id` untuk mutasi dari dokumen stok
 * opname. `occurred_at` terisi bila mutasi terjadi lebih awal dari saat dicatat (transaksi offline).
 *
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    /**
     * @return array{id: int, outlet_id: int, product: array{id: int, sku: string, name: string, unit: string}, type: string, type_label: string, quantity: numeric-string, stock_before: numeric-string, stock_after: numeric-string, unit_cost: int|null, note: string|null, sale_id: int|null, stock_count_id: int|null, user: UserSummaryResource|null, created_at: string, occurred_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'outlet_id' => $this->outlet_id,
            'product' => [
                'id' => $this->product_id,
                'sku' => $this->product?->sku,
                'name' => $this->product?->name,
                'unit' => $this->product?->unit,
            ],
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'quantity' => $this->quantity,
            'stock_before' => $this->stock_before,
            'stock_after' => $this->stock_after,
            'unit_cost' => $this->unit_cost,
            'note' => $this->note,
            'sale_id' => $this->reference_type === (new Sale)->getMorphClass() ? $this->reference_id : null,
            'stock_count_id' => $this->reference_type === (new StockCount)->getMorphClass() ? $this->reference_id : null,
            'user' => $this->user ? new UserSummaryResource($this->user) : null,
            'created_at' => $this->created_at->toIso8601String(),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
