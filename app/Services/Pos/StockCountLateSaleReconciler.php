<?php

namespace App\Services\Pos;

use App\Enums\StockCountStatus;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockCount;
use App\Models\StockCountCorrection;
use App\Models\StockCountItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\NumberFormatter;

/**
 * Penjualan offline yang terjadi sebelum barang dihitung tapi baru masuk setelah opname diselesaikan sudah
 * ikut "hilang" di hasil hitung, jadi potongan stoknya dikembalikan sekali lagi (koreksi susulan).
 */
class StockCountLateSaleReconciler
{
    public const WINDOW_DAYS = 30;

    public function __construct(private StockService $stock, private StockCountVariance $variance) {}

    /**
     * Dipanggil di transaksi checkout, setelah mutasi penjualan dibuat untuk produk yang sudah dikunci.
     *
     * @param  list<string>  $serials
     */
    public function reconcile(Sale $sale, SaleItem $saleItem, Product $product, StockMovement $movement, ?User $user, array $serials = []): ?StockCountCorrection
    {
        if ($movement->occurred_at === null || (float) $movement->quantity >= 0) {
            return null;
        }

        $item = StockCountItem::query()
            ->join('stock_counts as c', 'c.id', '=', 'stock_count_items.stock_count_id')
            ->where('stock_count_items.product_id', $product->id)
            ->where('c.outlet_id', $movement->outlet_id)
            ->where('c.status', StockCountStatus::Posted->value)
            ->where('c.posted_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->where('c.posted_at', '<', $movement->created_at)
            ->whereNotNull('stock_count_items.counted_qty')
            ->where('stock_count_items.reference_at', '>=', $movement->occurred_at)
            ->orderByDesc('c.posted_at')
            ->select('stock_count_items.*')
            ->first();

        if ($item === null) {
            return null;
        }

        $count = StockCount::query()->withoutGlobalScopes()->findOrFail($item->stock_count_id);
        $quantity = round(-(float) $movement->quantity, 3);
        $restore = $movement->batchLines->mapWithKeys(fn ($line) => [$line->product_batch_id => abs((float) $line->quantity)])->all();

        $correction = $this->stock->move(
            $product,
            StockMovementType::Opname,
            $quantity,
            $user,
            $count,
            "{$count->number} · Koreksi susulan {$sale->number}",
            $product->cost_price,
            (int) $movement->outlet_id,
            $restore === [] ? [] : ['restore' => $restore],
            notify: false,
        );

        if ($serials !== []) {
            ProductSerial::query()->where('product_id', $product->id)->whereIn('serial', SerialService::normalize($serials))
                ->where('status', ProductSerial::REMOVED)
                ->update(['status' => ProductSerial::SOLD, 'sale_item_id' => $saleItem->id, 'sold_at' => now()]);
        }

        $item->forceFill(['variance_qty' => round((float) $item->variance_qty + $quantity, 3)])->save();

        $row = StockCountCorrection::query()->create([
            'tenant_id' => $item->tenant_id,
            'stock_count_item_id' => $item->id,
            'sale_id' => $sale->id,
            'stock_movement_id' => $correction->id,
            'quantity' => $quantity,
        ]);

        $count->forceFill(['summary' => [...$count->summary ?? [], ...$this->variance->summarize($count)]])->save();

        activity()->performedOn($count)->causedBy($user)->event('updated')
            ->log("Koreksi susulan {$count->number}: {$product->name} +".NumberFormatter::quantity($quantity)." dari transaksi offline {$sale->number}.");

        return $row;
    }
}
