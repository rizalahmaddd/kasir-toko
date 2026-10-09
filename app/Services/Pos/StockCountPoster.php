<?php

namespace App\Services\Pos;

use App\Enums\StockCountStatus;
use App\Enums\StockMovementType;
use App\Events\ProductChanged;
use App\Events\StockCountUpdated;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\ProductStock;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use App\Models\StockCountItem;
use App\Models\StockCountSerial;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menyesuaikan stok dari dokumen berstatus posting, per potongan baris dalam transaksi terpisah supaya
 * kasir tidak tertahan lama. Baris yang sudah punya stock_movement_id dilewati, jadi aman diulang.
 */
class StockCountPoster
{
    public const CHUNK = 200;

    public const FLAG_BATCH_SHIFTED = 'batch_shifted';

    public function __construct(
        private StockService $stock,
        private StockCountVariance $variance,
        private StockCountNotifier $notifier,
    ) {}

    public function run(StockCount $count, User $user): StockCount
    {
        if ($count->status !== StockCountStatus::Posting) {
            throw new PosException("Opname {$count->number} tidak sedang diproses.", 'stock_count_closed');
        }

        $lowStock = [];
        $lastChanged = null;

        $count->items()->whereNull('stock_movement_id')->select('id')->chunkById(self::CHUNK, function (Collection $chunk) use ($count, $user, &$lowStock, &$lastChanged) {
            $changed = DB::transaction(fn () => $this->postChunk($count, $user, $chunk->pluck('id')->all()));

            foreach ($changed as $product) {
                $lastChanged = $product;

                if ($this->isLow($product, $count->outlet_id)) {
                    $lowStock[] = $product->id;
                }
            }
        });

        $count->forceFill([
            'status' => StockCountStatus::Posted,
            'posted_at' => now(),
            'summary' => [...$this->variance->summarize($count), 'low_stock' => count($lowStock)],
        ])->save();

        activity()->performedOn($count)->causedBy($user)->event('updated')
            ->log("Stok opname {$count->number} diselesaikan ({$count->summary['changed']} barang disesuaikan).");

        // Satu siaran untuk seluruh dokumen; layar stok cukup memuat ulang sekali.
        if ($lastChanged) {
            ProductChanged::dispatch($lastChanged);
        }

        StockCountUpdated::dispatch($count);
        $this->notifier->posted($count);

        return $count;
    }

    /**
     * Produk dikunci urut id, sama dengan checkout, supaya tidak saling menunggu (deadlock).
     *
     * @param  list<int>  $itemIds
     * @return list<Product> produk yang stoknya berubah
     */
    private function postChunk(StockCount $count, User $user, array $itemIds): array
    {
        $items = StockCountItem::query()->whereIn('id', $itemIds)->whereNull('stock_movement_id')->lockForUpdate()->get();
        $products = Product::withTrashed()->whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $items->each(fn (StockCountItem $item) => $item->setRelation('product', $products->get($item->product_id)));
        $this->variance->refresh($count, $items);
        $changed = [];

        foreach ($items->sortBy('product_id') as $item) {
            $product = $item->product;

            if (! $product->track_stock || $product->isVariantParent()) {
                continue;
            }

            if ($product->tracksSerials()) {
                if ($this->postSerials($count, $user, $item)) {
                    $changed[] = $product;
                }

                continue;
            }

            if ($item->counted_qty === null) {
                if ($count->uncounted_policy !== StockCount::UNCOUNTED_ZERO) {
                    continue;
                }

                $this->countAsZero($item, $user, (float) $this->stock->lockStock($product, $count->outlet_id)->stock);
            }

            $variance = (float) $item->variance_qty;
            $batch = $product->tracksBatches() ? $this->batchDeltas($count, $item) : [];

            if ($variance == 0.0 && ($batch === [] || ($batch['count_deltas']['deltas'] === [] && $batch['count_deltas']['new'] === []))) {
                continue;
            }

            $movement = $this->stock->move($product, StockMovementType::Opname, $variance, $user, $count, $this->note($count, $item), $product->cost_price, $count->outlet_id, $batch, notify: false);
            $item->forceFill(['stock_movement_id' => $movement->id, 'unit_cost' => $product->cost_price])->save();
            $changed[] = $product;
        }

        return $changed;
    }

    /**
     * Selisih per batch terhadap saldo batch saat terakhir dihitung, plus batch baru yang ditemukan. Batch
     * yang akan jadi minus (habis terjual setelah dihitung) membuat baris ditandai.
     *
     * @return array{count_deltas: array{deltas: array<int, float>, new: list<array{number: ?string, expires_at: ?string, quantity: float}>}}
     */
    private function batchDeltas(StockCount $count, StockCountItem $item): array
    {
        $entries = $item->activeEntries()->orderBy('counted_at')->orderBy('id')->get();
        $deltas = [];
        $new = [];

        foreach ($entries->whereNotNull('product_batch_id')->groupBy('product_batch_id') as $batchId => $batchEntries) {
            $deltas[(int) $batchId] = round((float) $batchEntries->sum('quantity_base') - (float) $batchEntries->last()->batch_system_qty, 3);
        }

        foreach ($entries->whereNull('product_batch_id')->whereNotNull('new_batch_number')->groupBy(fn (StockCountEntry $entry) => $entry->new_batch_number.'|'.$entry->new_batch_expires_at?->toDateString()) as $lot) {
            $new[] = [
                'number' => $lot->first()->new_batch_number,
                'expires_at' => $lot->first()->new_batch_expires_at?->toDateString(),
                'quantity' => round((float) $lot->sum('quantity_base'), 3),
            ];
        }

        $balances = ProductBatch::query()->whereIn('id', array_keys($deltas))->where('outlet_id', $count->outlet_id)->pluck('quantity', 'id');
        $shifted = collect($deltas)->contains(fn (float $delta, int $id) => (float) ($balances[$id] ?? 0) + $delta < 0);

        if ($shifted) {
            $item->forceFill(['flags' => array_values(array_unique([...$item->flags ?? [], self::FLAG_BATCH_SHIFTED]))]);
        }

        return ['count_deltas' => ['deltas' => array_filter($deltas, fn (float $delta) => $delta != 0.0), 'new' => $new]];
    }

    /**
     * Produk bernomor seri: unit dari outlet lain dipindahkan, unit tak tercatat didaftarkan, unit yang tidak
     * ter-scan dikeluarkan, lalu stok outlet disamakan dengan jumlah unit yang ada.
     */
    private function postSerials(StockCount $count, User $user, StockCountItem $item): bool
    {
        if ($item->counted_qty === null && $count->uncounted_policy !== StockCount::UNCOUNTED_ZERO) {
            return false;
        }

        $product = $item->product;
        $rows = StockCountSerial::query()->where('stock_count_id', $count->id)->where('product_id', $product->id)->get();
        $units = ProductSerial::query()->where('product_id', $product->id)->whereIn('serial', $rows->pluck('serial'))->lockForUpdate()->get()->keyBy('serial');
        $changed = false;

        foreach ($rows->where('action', StockCountSerial::ACTION_RELOCATE) as $row) {
            $unit = $units->get($row->serial);

            if (! $unit || $unit->status !== ProductSerial::IN_STOCK || $unit->outlet_id === $count->outlet_id) {
                continue;
            }

            $note = "{$count->number} · Pindah unit {$row->serial}";
            $this->stock->move($product, StockMovementType::TransferOut, -1, $user, $count, $note, $product->cost_price, $unit->outlet_id, notify: false);
            $this->stock->move($product, StockMovementType::TransferIn, 1, $user, $count, $note, $product->cost_price, $count->outlet_id, notify: false);
            $unit->update(['outlet_id' => $count->outlet_id]);
            $changed = true;
        }

        foreach ($rows->where('action', StockCountSerial::ACTION_REGISTER) as $row) {
            $unit = $units->get($row->serial);

            if ($unit === null) {
                ProductSerial::query()->create(['product_id' => $product->id, 'outlet_id' => $count->outlet_id, 'serial' => $row->serial, 'status' => ProductSerial::IN_STOCK]);
            } elseif ($unit->status !== ProductSerial::IN_STOCK) {
                $unit->update(['outlet_id' => $count->outlet_id, 'status' => ProductSerial::IN_STOCK, 'sale_item_id' => null, 'sold_at' => null]);
            }
        }

        $kept = $rows->filter(fn (StockCountSerial $row) => $row->result === StockCountSerial::RESULT_MATCHED
            || in_array($row->action, [StockCountSerial::ACTION_REGISTER, StockCountSerial::ACTION_RELOCATE], true))->pluck('serial');

        ProductSerial::query()->where('product_id', $product->id)->where('outlet_id', $count->outlet_id)->available()
            ->whereNotIn('serial', $kept)->update(['status' => ProductSerial::REMOVED]);

        $counted = ProductSerial::query()->where('product_id', $product->id)->where('outlet_id', $count->outlet_id)->available()->count();
        $system = (float) $this->stock->lockStock($product, $count->outlet_id)->stock;
        $variance = round($counted - $system, 3);

        $item->forceFill(['counted_qty' => $counted, 'reference_at' => $item->reference_at ?? now(), 'reference_system_qty' => $system, 'variance_qty' => $variance, 'unit_cost' => $product->cost_price]);

        if ($variance != 0.0) {
            $movement = $this->stock->move($product, StockMovementType::Opname, $variance, $user, $count, $this->note($count, $item), $product->cost_price, $count->outlet_id, notify: false);
            $item->forceFill(['stock_movement_id' => $movement->id]);
            $changed = true;
        }

        $item->save();

        return $changed;
    }

    private function countAsZero(StockCountItem $item, User $user, float $systemQty): void
    {
        $item->entries()->create([
            'tenant_id' => $item->tenant_id,
            'user_id' => $user->id,
            'client_uuid' => (string) Str::uuid(),
            'quantity_base' => 0,
            'system_qty_at_count' => $systemQty,
            'counted_at' => now(),
            'source' => StockCountEntry::SOURCE_POLICY,
            'note' => 'Belum dihitung, dianggap habis',
        ]);

        $item->forceFill([
            'counted_qty' => 0,
            'reference_at' => now(),
            'reference_system_qty' => $systemQty,
            'variance_qty' => round(-$systemQty, 3),
        ])->save();
    }

    private function isLow(Product $product, int $outletId): bool
    {
        $stock = ProductStock::query()->where('product_id', $product->id)->where('outlet_id', $outletId)->first(['stock', 'min_stock']);

        return $stock !== null && (float) $stock->stock <= (float) ($stock->min_stock ?? $product->min_stock);
    }

    private function note(StockCount $count, StockCountItem $item): string
    {
        return $item->reason ? "{$count->number} · {$item->reason->label()}" : $count->number;
    }
}
