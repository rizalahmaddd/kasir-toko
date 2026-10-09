<?php

namespace App\Services\Pos;

use App\Enums\StockCountScope;
use App\Models\Product;
use App\Models\Scopes\OutletAccessScope;
use App\Models\StockCount;
use App\Support\Features;
use Illuminate\Database\Eloquent\Builder;

/**
 * Menahan stok masuk/keluar manual, opname cepat, dan transfer untuk barang yang sedang dihitung di opname
 * yang menyalakan "tahan mutasi". Penjualan dan pembatalannya tidak pernah ditahan.
 */
class StockCountGuard
{
    /**
     * @param  iterable<int>  $productIds
     *
     * @throws PosException
     */
    public function ensureNotHeld(iterable $productIds, int $outletId): void
    {
        $productIds = collect($productIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($productIds->isEmpty() || ($count = $this->holding($productIds->all(), $outletId)) === null) {
            return;
        }

        $productId = $count->scope === StockCountScope::All
            ? $productIds->first()
            : (int) $count->items()->whereIn('product_id', $productIds)->value('product_id');
        $name = Product::withTrashed()->whereKey($productId)->value('name') ?? 'Barang ini';

        throw new PosException(
            "{$name} sedang dihitung di {$count->number}. Selesaikan atau batalkan opname itu dulu.",
            'stock_count_in_progress',
            ['stock_count_id' => $count->id, 'number' => $count->number, 'product_id' => $productId],
        );
    }

    /**
     * Dokumen yang menahan salah satu produk di outlet itu. Fitur yang dimatikan tidak boleh mengunci stok,
     * karena dokumennya tidak bisa dibuka untuk diselesaikan.
     *
     * @param  list<int>  $productIds
     */
    public function holding(array $productIds, int $outletId): ?StockCount
    {
        if (! Features::enabled('inventory.opname')) {
            return null;
        }

        return StockCount::query()
            ->withoutGlobalScope(OutletAccessScope::class)
            ->open()
            ->where('outlet_id', $outletId)
            ->where('hold_adjustments', true)
            ->where(fn (Builder $query) => $query
                ->where('scope', StockCountScope::All)
                ->orWhereHas('items', fn (Builder $items) => $items->whereIn('product_id', $productIds)))
            ->oldest('id')
            ->first();
    }
}
