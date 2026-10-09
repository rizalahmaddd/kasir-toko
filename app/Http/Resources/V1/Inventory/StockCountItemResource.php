<?php

namespace App\Http\Resources\V1\Inventory;

use App\Models\StockCountItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu barang di dokumen opname. Angka stok sistem dan selisih bernilai null bila penghitung tidak boleh
 * melihatnya. `counted_qty` null berarti belum dihitung. `batches` (saldo per batch) ada bila produk ber-batch.
 *
 * @mixin StockCountItem
 */
class StockCountItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->product;
        $system = $request->user()->can('inventory.opname.manage') || ! $this->stockCount->blind_count;

        return [
            'id' => $this->id,
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'variant' => $product->variantLabel(),
                'unit' => $product->unit,
                'category' => $product->category?->name,
                'track_batch' => $product->tracksBatches(),
                'track_serial' => $product->tracksSerials(),
                'units' => $product->units->map(fn ($unit) => ['id' => $unit->id, 'name' => $unit->name, 'factor' => (float) $unit->factor, 'barcode' => $unit->barcode])->values()->all(),
            ],
            'expected_qty' => $system ? (float) $this->expected_qty : null,
            'counted_qty' => $this->counted_qty !== null ? (float) $this->counted_qty : null,
            'reference_system_qty' => $system && $this->reference_system_qty !== null ? (float) $this->reference_system_qty : null,
            'variance_qty' => $system && $this->variance_qty !== null ? (float) $this->variance_qty : null,
            'variance_value' => $system && $this->variance_qty !== null ? (int) round((float) $this->variance_qty * (int) $this->unit_cost) : null,
            'reason' => $this->reason?->value,
            'reason_label' => $this->reason?->label(),
            'needs_recount' => $this->needs_recount,
            'flags' => $this->flags ?? [],
            'batches' => $product->relationLoaded('batches') && $product->tracksBatches()
                ? $product->batches->map(fn ($batch) => [
                    'id' => $batch->id,
                    'number' => $batch->batch_number,
                    'expires_at' => $batch->expires_at?->toDateString(),
                    'expired' => $batch->isExpired(),
                    'quantity' => $system ? (float) $batch->quantity : null,
                ])->values()->all()
                : [],
        ];
    }
}
