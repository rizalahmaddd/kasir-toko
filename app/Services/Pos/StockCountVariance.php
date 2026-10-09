<?php

namespace App\Services\Pos;

use App\Enums\StockMovementType;
use App\Models\HeldOrder;
use App\Models\ProductSerial;
use App\Models\Scopes\OutletAccessScope;
use App\Models\Setting;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use App\Models\StockCountItem;
use App\Models\StockCountSerial;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Selisih per barang dihitung terhadap stok sistem saat barang itu dihitung, bukan saat opname diselesaikan,
 * supaya toko tetap bisa berjualan selama opname tanpa merusak hasil hitung.
 */
class StockCountVariance
{
    public const RECOUNT_PERCENT_KEY = 'inventory.opname.recount_percent';

    public const FLAG_INACTIVE = 'inactive';

    public const FLAG_DELETED = 'deleted';

    public const FLAG_NOT_TRACKED = 'not_tracked';

    public const FLAG_MANUAL_MOVEMENT = 'manual_movement_after_count';

    public const FLAG_SPLIT_ENTRIES = 'split_entries';

    public const FLAG_RECOUNT_SUGGESTED = 'recount_suggested';

    public const FLAG_PENDING_HELD_ORDER = 'pending_held_order';

    /**
     * Penanda yang selalu dihitung ulang di sini; penanda lain di kolom flags dibiarkan.
     */
    private const DERIVED_FLAGS = [
        self::FLAG_INACTIVE,
        self::FLAG_DELETED,
        self::FLAG_NOT_TRACKED,
        self::FLAG_MANUAL_MOVEMENT,
        self::FLAG_SPLIT_ENTRIES,
        self::FLAG_RECOUNT_SUGGESTED,
        self::FLAG_PENDING_HELD_ORDER,
    ];

    private const SPLIT_ENTRIES_MINUTES = 120;

    /**
     * Hasil hitung, stok sistem acuan, selisih, dan HPP untuk baris-baris satu dokumen.
     *
     * @param  Collection<int, StockCountItem>  $items
     */
    public function refresh(StockCount $count, Collection $items): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $items->loadMissing('product');
        $ids = $items->pluck('id')->all();
        $entries = StockCountEntry::query()->whereIn('stock_count_item_id', $ids)->whereNull('voided_at')
            ->orderBy('counted_at')->orderBy('id')->get()->groupBy('stock_count_item_id');
        $late = $this->lateMovements($count, $ids);
        $manual = $this->manualMovementsAfterCount($count, $ids);
        $recountPercent = (float) Setting::get(self::RECOUNT_PERCENT_KEY, '20');
        $serialCounts = $this->serialCounts($count, $items);
        $held = $this->heldProductIds($count);

        foreach ($items as $item) {
            $product = $item->product;
            $itemEntries = $entries->get($item->id, collect());
            $flags = array_values(array_diff($item->flags ?? [], self::DERIVED_FLAGS));

            if (! $product->is_active) {
                $flags[] = self::FLAG_INACTIVE;
            }

            if ($product->trashed()) {
                $flags[] = self::FLAG_DELETED;
            }

            if (! $product->track_stock || $product->isVariantParent()) {
                $flags[] = self::FLAG_NOT_TRACKED;
            }

            if (in_array($item->id, $manual, true)) {
                $flags[] = self::FLAG_MANUAL_MOVEMENT;
            }

            if (isset($held[$item->product_id])) {
                $flags[] = self::FLAG_PENDING_HELD_ORDER;
            }

            if ($product->tracksSerials()) {
                $item->forceFill($serialCounts[$item->product_id] ?? ['counted_qty' => null, 'reference_at' => null, 'reference_system_qty' => null, 'variance_qty' => null]);
            } elseif ($itemEntries->isEmpty()) {
                $item->forceFill(['counted_qty' => null, 'reference_at' => null, 'reference_system_qty' => null, 'variance_qty' => null]);
            } else {
                $reference = $itemEntries->last();
                $system = round((float) $reference->system_qty_at_count + ($late[$item->id] ?? 0.0), 3);
                $counted = round((float) $itemEntries->sum('quantity_base'), 3);
                $variance = round($counted - $system, 3);

                if (abs($itemEntries->first()->counted_at->diffInMinutes($reference->counted_at)) > self::SPLIT_ENTRIES_MINUTES) {
                    $flags[] = self::FLAG_SPLIT_ENTRIES;
                }

                if ($variance != 0.0 && abs($variance) * 100 > $recountPercent * max(abs($system), 1)) {
                    $flags[] = self::FLAG_RECOUNT_SUGGESTED;
                }

                $item->forceFill([
                    'counted_qty' => $counted,
                    'reference_at' => $reference->counted_at,
                    'reference_system_qty' => $system,
                    'variance_qty' => $variance,
                ]);
            }

            $item->forceFill(['unit_cost' => $product->cost_price, 'flags' => $flags === [] ? null : $flags]);

            if ($item->isDirty()) {
                $item->save();
            }
        }
    }

    /**
     * Barang di bill terbuka atau transaksi tertunda outlet ini. Stoknya baru terpotong saat dibayar, padahal
     * barangnya bisa sudah tidak di rak, jadi barisnya ditandai (tidak dikoreksi otomatis).
     *
     * @return array<int, true>
     */
    private function heldProductIds(StockCount $count): array
    {
        return HeldOrder::query()->withoutGlobalScope(OutletAccessScope::class)->where('outlet_id', $count->outlet_id)->get(['cart'])
            ->flatMap(fn (HeldOrder $order) => collect($order->cart['items'] ?? [])->pluck('product_id'))
            ->filter()
            ->mapWithKeys(fn ($productId) => [(int) $productId => true])
            ->all();
    }

    /**
     * Hasil hitung produk bernomor seri: unit cocok yang masih ada di outlet ini ditambah unit yang akan
     * didaftarkan atau dipindahkan ke sini. Unit yang terjual setelah di-scan tidak ikut, dan stok acuannya
     * stok sekarang, jadi penjualan selama opname tidak membuat selisih palsu.
     *
     * @param  Collection<int, StockCountItem>  $items
     * @return array<int, array{counted_qty: float, reference_at: mixed, reference_system_qty: float, variance_qty: float}>
     */
    private function serialCounts(StockCount $count, Collection $items): array
    {
        $productIds = $items->filter(fn (StockCountItem $item) => $item->product->tracksSerials())->pluck('product_id')->all();

        if ($productIds === []) {
            return [];
        }

        $rows = StockCountSerial::query()->where('stock_count_id', $count->id)->whereIn('product_id', $productIds)->get()->groupBy('product_id');
        $inStock = ProductSerial::query()->whereIn('product_id', $productIds)->where('outlet_id', $count->outlet_id)->available()
            ->whereIn('serial', $rows->flatten()->where('result', StockCountSerial::RESULT_MATCHED)->pluck('serial'))
            ->get(['product_id', 'serial'])
            ->map(fn (ProductSerial $unit) => "{$unit->product_id}|{$unit->serial}")
            ->flip();
        $results = [];

        foreach ($items as $item) {
            $scans = $rows->get($item->product_id);

            if (! $scans || $scans->isEmpty()) {
                continue;
            }

            $counted = (float) $scans->filter(fn (StockCountSerial $row) => $row->result === StockCountSerial::RESULT_MATCHED
                ? $inStock->has("{$row->product_id}|{$row->serial}")
                : in_array($row->action, [StockCountSerial::ACTION_REGISTER, StockCountSerial::ACTION_RELOCATE], true))->count();
            $system = round(app(StockService::class)->outletStock($item->product, $count->outlet_id), 3);

            $results[$item->product_id] = [
                'counted_qty' => $counted,
                'reference_at' => $scans->max('scanned_at'),
                'reference_system_qty' => $system,
                'variance_qty' => round($counted - $system, 3),
            ];
        }

        return $results;
    }

    /**
     * Ringkasan dampak dokumen dari nilai yang sudah tersimpan di barisnya.
     *
     * @return array{items: int, counted: int, changed: int, shortage_qty: float, shortage_value: int, surplus_qty: float, surplus_value: int, uncounted: int}
     */
    public function summarize(StockCount $count): array
    {
        $row = DB::table('stock_count_items')
            ->where('tenant_id', $count->tenant_id)
            ->where('stock_count_id', $count->id)
            ->selectRaw('COUNT(*) as items')
            ->selectRaw('SUM(CASE WHEN counted_qty IS NOT NULL THEN 1 ELSE 0 END) as counted')
            ->selectRaw('SUM(CASE WHEN variance_qty <> 0 THEN 1 ELSE 0 END) as changed')
            ->selectRaw('SUM(CASE WHEN variance_qty < 0 THEN -variance_qty ELSE 0 END) as shortage_qty')
            ->selectRaw('SUM(CASE WHEN variance_qty < 0 THEN -variance_qty * COALESCE(unit_cost, 0) ELSE 0 END) as shortage_value')
            ->selectRaw('SUM(CASE WHEN variance_qty > 0 THEN variance_qty ELSE 0 END) as surplus_qty')
            ->selectRaw('SUM(CASE WHEN variance_qty > 0 THEN variance_qty * COALESCE(unit_cost, 0) ELSE 0 END) as surplus_value')
            ->first();

        return [
            'items' => (int) $row->items,
            'counted' => (int) $row->counted,
            'changed' => (int) $row->changed,
            'shortage_qty' => round((float) $row->shortage_qty, 3),
            'shortage_value' => (int) round((float) $row->shortage_value),
            'surplus_qty' => round((float) $row->surplus_qty, 3),
            'surplus_value' => (int) round((float) $row->surplus_value),
            'uncounted' => (int) $row->items - (int) $row->counted,
        ];
    }

    /**
     * Mutasi yang terjadi sebelum barang dihitung tapi baru tercatat sesudahnya (mis. penjualan offline).
     *
     * @param  list<int>  $itemIds
     * @return array<int, float> stock_count_item_id => jumlah bertanda
     */
    private function lateMovements(StockCount $count, array $itemIds): array
    {
        return $this->movementsOfItems($count, $itemIds)
            ->whereNotNull('m.occurred_at')
            ->whereColumn('m.created_at', '>', 'i.reference_at')
            ->whereColumn('m.occurred_at', '<=', 'i.reference_at')
            ->groupBy('i.id')
            ->selectRaw('i.id as item_id, SUM(m.quantity) as quantity')
            ->pluck('quantity', 'item_id')
            ->map(fn ($quantity) => (float) $quantity)
            ->all();
    }

    /**
     * Baris yang stoknya diubah manual atau ditransfer setelah dihitung. Tanpa "tahan mutasi" hal ini
     * tidak bisa dibedakan dari barang yang datang sebelum dihitung tapi dicatat sesudahnya.
     *
     * @param  list<int>  $itemIds
     * @return list<int>
     */
    private function manualMovementsAfterCount(StockCount $count, array $itemIds): array
    {
        return $this->movementsOfItems($count, $itemIds)
            ->whereIn('m.type', [
                StockMovementType::StockIn->value,
                StockMovementType::StockOut->value,
                StockMovementType::Opname->value,
                StockMovementType::TransferIn->value,
                StockMovementType::TransferOut->value,
            ])
            ->whereColumn('m.created_at', '>', 'i.reference_at')
            ->distinct()
            ->pluck('i.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $itemIds
     */
    private function movementsOfItems(StockCount $count, array $itemIds): Builder
    {
        return DB::table('stock_movements as m')
            ->join('stock_count_items as i', 'i.product_id', '=', 'm.product_id')
            ->whereIn('i.id', $itemIds)
            ->whereNotNull('i.reference_at')
            ->where('m.tenant_id', $count->tenant_id)
            ->where('m.outlet_id', $count->outlet_id)
            ->where(fn (Builder $query) => $query
                ->whereNull('m.reference_type')
                ->orWhere('m.reference_type', '!=', $count->getMorphClass())
                ->orWhere('m.reference_id', '!=', $count->id));
    }
}
