<?php

namespace App\Services\Pos;

use App\Enums\BatchSource;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductStock;
use App\Support\NumberFormatter;
use App\Support\PosSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pembukuan stok per batch. Hanya dipanggil dari StockService::move(), di dalam transaksi yang sudah
 * mengunci baris product_stocks, supaya jumlah batch per (produk, outlet) selalu sama dengan stok outletnya.
 */
class BatchService
{
    /**
     * Samakan jumlah batch dengan stok outlet. Selisih muncul kalau stok berubah selama kapabilitas
     * mati, atau saat produk pertama kali dilacak batch-nya; selisihnya dicatat di batch tanpa nomor.
     */
    public function reconcile(Product $product, int $outletId, float $stock): void
    {
        $current = (float) ProductBatch::query()->where('product_id', $product->id)->where('outlet_id', $outletId)->sum('quantity');
        $difference = round($stock - $current, 3);

        if ($difference == 0.0) {
            return;
        }

        if ($difference > 0) {
            $source = $current == 0.0 && ! ProductBatch::query()->where('product_id', $product->id)->where('outlet_id', $outletId)->exists()
                ? BatchSource::Opening
                : BatchSource::Adjustment;

            $this->receive($product, $outletId, $difference, null, null, $product->cost_price, $source);

            return;
        }

        $this->allocateOut($product, $outletId, -$difference, true);
    }

    /**
     * Rekonsiliasi semua produk ber-batch di semua outlet, mis. saat kapabilitas baru dinyalakan.
     */
    public function reconcileAll(): int
    {
        $count = 0;

        Product::query()->where('track_stock', true)->where('track_batch', true)->orderBy('id')->chunkById(200, function ($products) use (&$count) {
            foreach ($products as $product) {
                $count += $this->reconcileProduct($product);
            }
        });

        return $count;
    }

    public function reconcileProduct(Product $product): int
    {
        return DB::transaction(function () use ($product) {
            $stocks = ProductStock::query()->where('product_id', $product->id)->orderBy('outlet_id')->lockForUpdate()->get();

            foreach ($stocks as $stock) {
                $this->reconcile($product, $stock->outlet_id, (float) $stock->stock);
            }

            return $stocks->count();
        });
    }

    /**
     * Stok masuk ke satu batch. Batch dengan nomor & tanggal kedaluwarsa yang sama di outlet itu digabung.
     *
     * @return array<int, float> product_batch_id => jumlah (positif)
     */
    public function receive(Product $product, int $outletId, float $quantity, ?string $number, Carbon|string|null $expiresAt, ?int $unitCost, BatchSource $source): array
    {
        $number = filled($number) ? mb_substr(trim($number), 0, 50) : null;
        $expires = $expiresAt ? Carbon::parse($expiresAt)->toDateString() : null;

        $batch = ProductBatch::query()
            ->where('product_id', $product->id)
            ->where('outlet_id', $outletId)
            ->when($number === null, fn ($query) => $query->whereNull('batch_number'), fn ($query) => $query->where('batch_number', $number))
            ->when($expires === null, fn ($query) => $query->whereNull('expires_at'), fn ($query) => $query->whereDate('expires_at', $expires))
            ->lockForUpdate()
            ->first();

        if ($batch) {
            $batch->quantity = round((float) $batch->quantity + $quantity, 3);
            $batch->unit_cost ??= $unitCost;
            $batch->save();
        } else {
            $batch = ProductBatch::query()->create([
                'product_id' => $product->id,
                'outlet_id' => $outletId,
                'batch_number' => $number,
                'expires_at' => $expires,
                'quantity' => round($quantity, 3),
                'unit_cost' => $unitCost,
                'received_at' => now(),
                'source' => $source,
            ]);
        }

        return [$batch->id => round($quantity, 3)];
    }

    /**
     * Unit per produk di outlet ini yang kedaluwarsa dalam ambang diskon ED dekat. Batch yang sudah lewat ikut
     * dihitung hanya bila toko mengizinkan menjual batch kedaluwarsa, sama seperti urutan FEFO di allocateOut().
     *
     * @param  iterable<int>  $productIds
     * @return array<int, float> product_id => jumlah satuan dasar
     */
    public static function nearExpiryQuantities(iterable $productIds, int $outletId): array
    {
        if (PosSettings::nearExpiryDiscountPercent() <= 0) {
            return [];
        }

        return ProductBatch::query()
            ->whereIn('product_id', collect($productIds)->all())
            ->where('outlet_id', $outletId)
            ->where('quantity', '>', 0)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', today()->addDays(PosSettings::nearExpiryDiscountDays()))
            ->when(PosSettings::blockExpiredSale(), fn ($query) => $query->whereDate('expires_at', '>=', today()))
            ->whereHas('product', fn ($query) => $query->where('track_batch', true)->where('track_stock', true))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as total')
            ->pluck('total', 'product_id')
            ->map(fn ($total) => round((float) $total, 3))
            ->all();
    }

    /**
     * Setel batch sesuai hitungan opname. Mengembalikan perubahan bertanda per batch (bukan nilai mutlak).
     *
     * @param  array<int, float|int|string>  $counts
     * @param  array{number: ?string, expires_at: ?string, quantity: float}|null  $extra
     * @return array<int, float>
     */
    public function applyCounts(Product $product, int $outletId, array $counts, ?array $extra): array
    {
        $changes = [];
        $batches = ProductBatch::query()->where('product_id', $product->id)->where('outlet_id', $outletId)->whereIn('id', array_keys($counts))->lockForUpdate()->get()->keyBy('id');

        foreach ($counts as $batchId => $counted) {
            $batch = $batches->get((int) $batchId);
            $difference = $batch ? round((float) $counted - (float) $batch->quantity, 3) : 0.0;

            if ($difference == 0.0) {
                continue;
            }

            $batch->quantity = round((float) $counted, 3);
            $batch->save();
            $changes[$batch->id] = $difference;
        }

        if ($extra !== null && (float) $extra['quantity'] > 0) {
            foreach ($this->receive($product, $outletId, (float) $extra['quantity'], $extra['number'] ?? null, $extra['expires_at'] ?? null, $product->cost_price, BatchSource::Adjustment) as $id => $amount) {
                $changes[$id] = round(($changes[$id] ?? 0) + $amount, 3);
            }
        }

        return $changes;
    }

    /**
     * Opname dokumen: tiap batch ditambah/dikurangi sebesar selisihnya terhadap saldo saat dihitung, bukan
     * disetel ke hasil hitung, supaya penjualan setelah barang dihitung tidak hilang. Sisa dari $total
     * (hitungan tanpa batch dikurangi saldo batch yang tidak dihitung) diambil FEFO dari batch yang tidak
     * dihitung, atau masuk ke batch tanpa nomor. Batch yang akan jadi minus dinolkan dan kekurangannya ikut sisa.
     *
     * @param  array<int, float>  $deltas  product_batch_id => selisih bertanda
     * @param  list<array{number: ?string, expires_at: ?string, quantity: float}>  $newBatches
     * @return array<int, float> perubahan bertanda per batch, totalnya sama dengan $total
     */
    public function applyCountDeltas(Product $product, int $outletId, float $total, array $deltas, array $newBatches = []): array
    {
        $changes = [];
        $batches = ProductBatch::query()->where('product_id', $product->id)->where('outlet_id', $outletId)->whereIn('id', array_keys($deltas))->lockForUpdate()->get()->keyBy('id');

        foreach ($deltas as $batchId => $delta) {
            $batch = $batches->get((int) $batchId);
            $delta = $batch ? max(round((float) $delta, 3), -(float) $batch->quantity) : 0.0;

            if ($delta == 0.0) {
                continue;
            }

            $batch->quantity = round((float) $batch->quantity + $delta, 3);
            $batch->save();
            $changes[$batch->id] = $delta;
        }

        foreach ($newBatches as $lot) {
            if ((float) $lot['quantity'] <= 0) {
                continue;
            }

            foreach ($this->receive($product, $outletId, (float) $lot['quantity'], $lot['number'], $lot['expires_at'], $product->cost_price, BatchSource::Adjustment) as $id => $amount) {
                $changes[$id] = round(($changes[$id] ?? 0) + $amount, 3);
            }
        }

        $rest = round($total - array_sum($changes), 3);

        if ($rest > 0) {
            foreach ($this->receive($product, $outletId, $rest, null, null, $product->cost_price, BatchSource::Adjustment) as $id => $amount) {
                $changes[$id] = round(($changes[$id] ?? 0) + $amount, 3);
            }
        } elseif ($rest < 0) {
            $rest = -$rest;
            $uncounted = ProductBatch::query()->where('product_id', $product->id)->where('outlet_id', $outletId)->where('quantity', '>', 0)
                ->whereNotIn('id', array_keys($deltas))->fefo()->lockForUpdate()->get();

            foreach ($uncounted as $batch) {
                if ($rest <= 0) {
                    break;
                }

                $take = min((float) $batch->quantity, $rest);
                $batch->quantity = round((float) $batch->quantity - $take, 3);
                $batch->save();
                $changes[$batch->id] = round(($changes[$batch->id] ?? 0) - $take, 3);
                $rest = round($rest - $take, 3);
            }

            if ($rest > 0) {
                foreach ($this->allocateOut($product, $outletId, $rest, true)['allocations'] as $id => $amount) {
                    $changes[$id] = round(($changes[$id] ?? 0) - $amount, 3);
                }
            }
        }

        return array_filter($changes, fn (float $change) => $change != 0.0);
    }

    /**
     * Kembalikan stok ke batch tertentu (batal penjualan). Batch yang sudah tidak ada diganti batch tanpa nomor.
     *
     * @param  array<int, float>  $allocations  product_batch_id => jumlah
     * @return array<int, float>
     */
    public function restore(Product $product, int $outletId, array $allocations, float $quantity, BatchSource $source): array
    {
        $restored = [];
        $left = round($quantity, 3);
        $batches = ProductBatch::query()->whereIn('id', array_keys($allocations))->where('outlet_id', $outletId)->lockForUpdate()->get()->keyBy('id');

        foreach ($allocations as $batchId => $amount) {
            $batch = $batches->get($batchId);
            $amount = min(round((float) $amount, 3), $left);

            if (! $batch || $amount <= 0) {
                continue;
            }

            $batch->quantity = round((float) $batch->quantity + $amount, 3);
            $batch->save();
            $restored[$batch->id] = $amount;
            $left = round($left - $amount, 3);
        }

        if ($left > 0) {
            foreach ($this->receive($product, $outletId, $left, null, null, $product->cost_price, $source) as $id => $amount) {
                $restored[$id] = round(($restored[$id] ?? 0) + $amount, 3);
            }
        }

        return $restored;
    }

    /**
     * Ambil stok dengan urutan FEFO (batch pilihan dulu kalau ada). Batch kedaluwarsa dilewati kecuali
     * $allowExpired; kekurangan karena stok minus dicatat sebagai saldo negatif di batch tanpa nomor.
     *
     * @return array{allocations: array<int, float>, expired: bool}
     *
     * @throws PosException kode expired_batch bila sisa stok yang layak jual hanya batch kedaluwarsa.
     */
    public function allocateOut(Product $product, int $outletId, float $quantity, bool $allowExpired, ?int $preferredBatchId = null): array
    {
        $batches = ProductBatch::query()
            ->where('product_id', $product->id)
            ->where('outlet_id', $outletId)
            ->where('quantity', '>', 0)
            ->fefo()
            ->lockForUpdate()
            ->get();

        if ($preferredBatchId !== null) {
            $batches = $batches->sortBy(fn (ProductBatch $batch) => $batch->id === $preferredBatchId ? 0 : 1)->values();
        }

        $left = round($quantity, 3);
        $allocations = [];
        $expiredUsed = false;
        $expiredSkipped = 0.0;

        foreach ($batches as $batch) {
            if ($left <= 0) {
                break;
            }

            if ($batch->isExpired() && ! $allowExpired) {
                $expiredSkipped += (float) $batch->quantity;

                continue;
            }

            $take = min((float) $batch->quantity, $left);
            $batch->quantity = round((float) $batch->quantity - $take, 3);
            $batch->save();

            $allocations[$batch->id] = round(($allocations[$batch->id] ?? 0) + $take, 3);
            $expiredUsed = $expiredUsed || $batch->isExpired();
            $left = round($left - $take, 3);
        }

        if ($left > 0 && $expiredSkipped > 0) {
            $usable = NumberFormatter::quantity(round($quantity - $left, 3));

            throw new PosException("Stok {$product->name} yang belum kedaluwarsa hanya {$usable} {$product->unit}. Sisanya sudah lewat tanggal kedaluwarsa dan tidak boleh dijual.", 'expired_batch', ['product_id' => $product->id]);
        }

        if ($left > 0) {
            $loose = $this->receive($product, $outletId, -$left, null, null, $product->cost_price, BatchSource::Adjustment);

            foreach ($loose as $id => $amount) {
                $allocations[$id] = round(($allocations[$id] ?? 0) - $amount, 3);
            }
        }

        return ['allocations' => $allocations, 'expired' => $expiredUsed];
    }
}
