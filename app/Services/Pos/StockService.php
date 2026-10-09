<?php

namespace App\Services\Pos;

use App\Enums\BatchSource;
use App\Enums\StockMovementType;
use App\Events\ProductChanged;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductStock;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\StockMovementBatch;
use App\Models\User;
use App\Services\StockAlertNotifier;
use App\Support\CurrentOutlet;
use App\Support\NumberFormatter;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockService
{
    public function __construct(private BatchService $batches, private SerialService $serials, private StockCountGuard $countGuard) {}

    /**
     * Mutasi stok di satu outlet untuk produk yang sudah dikunci (lockForUpdate) oleh pemanggil.
     * $quantity bertanda (satuan dasar): positif menambah, negatif mengurangi. products.stock (total semua
     * outlet) ikut berubah di transaksi yang sama; stock_before/after pada kartu stok adalah stok outlet.
     *
     * Untuk produk ber-batch, $batch mengarahkan batch mana yang dipakai: number & expires_at (stok masuk),
     * restore (batal penjualan, product_batch_id => jumlah), lots (transfer masuk), batch_id (batch pilihan
     * saat keluar), count_deltas (opname dokumen, lihat BatchService::applyCountDeltas), dan allow_expired
     * (default true; checkout online mematikannya). Alokasi batch yang terjadi tersedia di relasi batchLines
     * pada mutasi yang dikembalikan.
     *
     * $occurredAt diisi bila mutasi terjadi lebih awal dari saat dicatat (penjualan offline); opname memakainya
     * untuk mengenali penjualan yang terjadi sebelum barang dihitung. $notify false meredam peringatan stok
     * menipis per produk, untuk pemrosesan massal yang mengirim ringkasannya sendiri.
     *
     * @param  array{number?: ?string, expires_at?: ?string, restore?: array<int, float>, lots?: list<array{number: ?string, expires_at: ?string, quantity: float, unit_cost: ?int}>, batch_id?: ?int, allow_expired?: bool, counts?: array<int, float|int|string>, count_deltas?: array{deltas: array<int, float>, new: list<array{number: ?string, expires_at: ?string, quantity: float}>}, extra?: ?array{number: ?string, expires_at: ?string, quantity: float}}  $batch
     */
    public function move(Product $product, StockMovementType $type, float $quantity, ?User $user, ?Model $reference = null, ?string $note = null, ?int $unitCost = null, ?int $outletId = null, array $batch = [], ?CarbonInterface $occurredAt = null, bool $notify = true): StockMovement
    {
        $outletId ??= $this->currentOutletId();
        $stock = $this->lockStock($product, $outletId);

        $before = (float) $stock->stock;
        $after = round($before + $quantity, 3);
        $allocations = $product->tracksBatches() ? $this->allocateBatches($product, $type, $quantity, $outletId, $before, $unitCost, $batch) : [];

        $stock->stock = $after;
        $stock->save();

        // Kartu stok (stock_movements) sudah jadi jejaknya; save biasa akan menulis audit log dan
        // broadcast untuk setiap barang di setiap transaksi.
        $product->stock = round((float) $product->stock + $quantity, 3);
        $product->saveQuietly();

        if ($notify) {
            app(StockAlertNotifier::class)->notifyIfNeeded($product, $before, $after, $outletId, $stock->min_stock !== null ? (float) $stock->min_stock : null);
        }

        $movement = StockMovement::create([
            'outlet_id' => $outletId,
            'product_id' => $product->id,
            'user_id' => $user?->id,
            'type' => $type,
            'quantity' => $quantity,
            'stock_before' => $before,
            'stock_after' => $after,
            'unit_cost' => $unitCost,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'note' => $note,
            'occurred_at' => $occurredAt,
        ]);

        $signed = isset($batch['counts']) || isset($batch['count_deltas']);
        $lines = collect($allocations)->map(fn (float $amount, int $batchId) => StockMovementBatch::query()->create([
            'stock_movement_id' => $movement->id,
            'product_batch_id' => $batchId,
            'quantity' => $signed || $quantity >= 0 ? $amount : -$amount,
        ]))->values();

        return $movement->setRelation('batchLines', $lines);
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<int, float> product_batch_id => jumlah (positif)
     */
    private function allocateBatches(Product $product, StockMovementType $type, float $quantity, int $outletId, float $before, ?int $unitCost, array $intent): array
    {
        $this->batches->reconcile($product, $outletId, $before);

        if (isset($intent['count_deltas'])) {
            return $this->batches->applyCountDeltas($product, $outletId, $quantity, $intent['count_deltas']['deltas'] ?? [], $intent['count_deltas']['new'] ?? []);
        }

        if (isset($intent['counts'])) {
            return $this->batches->applyCounts($product, $outletId, $intent['counts'], $intent['extra'] ?? null);
        }

        if ($quantity < 0) {
            return $this->batches->allocateOut($product, $outletId, -$quantity, $intent['allow_expired'] ?? true, $intent['batch_id'] ?? null)['allocations'];
        }

        if (isset($intent['restore'])) {
            return $this->batches->restore($product, $outletId, $intent['restore'], $quantity, BatchSource::SaleVoid);
        }

        if (isset($intent['lots'])) {
            $allocations = [];
            $left = $quantity;

            foreach ($intent['lots'] as $lot) {
                $amount = min(round((float) $lot['quantity'], 3), $left);

                if ($amount <= 0) {
                    continue;
                }

                $allocations += $this->batches->receive($product, $outletId, $amount, $lot['number'], $lot['expires_at'], $lot['unit_cost'] ?? $unitCost, BatchSource::Transfer);
                $left = round($left - $amount, 3);
            }

            return $left > 0 ? $allocations + $this->batches->receive($product, $outletId, $left, null, null, $unitCost, BatchSource::Transfer) : $allocations;
        }

        $source = match ($type) {
            StockMovementType::Initial => BatchSource::Opening,
            StockMovementType::StockIn => BatchSource::StockIn,
            StockMovementType::SaleVoid => BatchSource::SaleVoid,
            default => BatchSource::Adjustment,
        };

        return $this->batches->receive($product, $outletId, $quantity, $intent['number'] ?? null, $intent['expires_at'] ?? null, $unitCost ?? $product->cost_price, $source);
    }

    /**
     * Baris stok produk di outlet, dikunci. Dibuat kalau belum ada: outlet utama mewarisi sisa
     * stok total yang belum terbagi ke outlet lain, outlet lain mulai dari nol.
     */
    public function lockStock(Product $product, int $outletId): ProductStock
    {
        $query = fn () => ProductStock::query()->where('product_id', $product->id)->where('outlet_id', $outletId)->lockForUpdate()->first();

        if ($stock = $query()) {
            return $stock;
        }

        try {
            return DB::transaction(fn () => ProductStock::query()->create([
                'product_id' => $product->id,
                'outlet_id' => $outletId,
                'stock' => $this->initialStock($product, $outletId),
            ]));
        } catch (UniqueConstraintViolationException) {
            return $query() ?? throw new PosException('Stok outlet tidak bisa dimuat, coba lagi.');
        }
    }

    /**
     * Stok outlet untuk beberapa produk sekaligus (dikunci), diindeks product_id. Dipakai checkout.
     *
     * @param  iterable<int>  $productIds
     * @return Collection<int, ProductStock>
     */
    public function lockStocks(iterable $productIds, int $outletId): Collection
    {
        $ids = collect($productIds)->unique()->sort()->values();
        $stocks = ProductStock::query()->where('outlet_id', $outletId)->whereIn('product_id', $ids)->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');

        foreach ($ids->diff($stocks->keys()) as $missingId) {
            $product = Product::withTrashed()->find($missingId);

            if ($product) {
                $stocks->put((int) $missingId, $this->lockStock($product, $outletId));
            }
        }

        return $stocks;
    }

    /**
     * Stok produk di outlet tanpa mengunci atau membuat baris stok; nilainya sama dengan yang akan dipakai lockStock().
     */
    public function outletStock(Product $product, int $outletId): float
    {
        $stock = ProductStock::query()->where('product_id', $product->id)->where('outlet_id', $outletId)->value('stock');

        return $stock === null ? $this->initialStock($product, $outletId) : (float) $stock;
    }

    private function initialStock(Product $product, int $outletId): float
    {
        $primaryId = Outlet::query()->where('is_primary', true)->value('id');

        if ($primaryId !== null && (int) $primaryId !== $outletId) {
            return 0.0;
        }

        $others = (float) ProductStock::query()->where('product_id', $product->id)->where('outlet_id', '!=', $outletId)->sum('stock');

        return round((float) $product->stock - $others, 3);
    }

    /**
     * Penyesuaian manual dari halaman Stok. Opname: $quantity adalah hasil hitung fisik di outlet itu
     * (satuan dasar), bukan selisih. Stok masuk/keluar boleh dalam satuan lain ($unit): jumlah dan harga
     * beli dikonversi ke satuan dasar. Stok masuk dengan harga beli memperbarui HPP (satu per produk)
     * dengan rata-rata tertimbang dari stok total semua outlet.
     *
     * Produk bernomor seri wajib menyebut nomor serinya ($batch['serials']) untuk stok masuk & keluar, dan
     * tidak bisa diopname langsung.
     *
     * @param  array{number?: ?string, expires_at?: ?string, batch_id?: ?int, serials?: list<string>}  $batch
     */
    public function adjust(Product $product, StockMovementType $type, float $quantity, User $user, ?string $note = null, ?int $unitCost = null, ?int $outletId = null, ?ProductUnit $unit = null, array $batch = []): StockMovement
    {
        if (! in_array($type, [StockMovementType::StockIn, StockMovementType::StockOut, StockMovementType::Opname], true)) {
            throw new PosException('Jenis penyesuaian stok tidak dikenal.');
        }

        if ($unit !== null && $type !== StockMovementType::Opname) {
            if ($unit->product_id !== $product->id) {
                throw new PosException('Satuan tidak cocok dengan produknya.');
            }

            $factor = (float) $unit->factor;
            $quantity = round($quantity * $factor, 3);
            $unitCost = $unitCost === null ? null : (int) round($unitCost / $factor);
            $note = trim(($note ?? '').' ('.NumberFormatter::quantity($quantity / $factor)." {$unit->name})");
        }

        $outletId ??= $this->currentOutletId();
        app(CurrentOutlet::class)->ensureOperational($outletId);
        $this->countGuard->ensureNotHeld([$product->id], $outletId);

        return DB::transaction(function () use ($product, $type, $quantity, $user, $note, $unitCost, $outletId, $batch) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if (! $locked->track_stock) {
                throw new PosException("Stok {$locked->name} tidak dilacak. Aktifkan \"Lacak stok\" di data produk dulu.");
            }

            if ($type === StockMovementType::StockIn && $locked->tracksBatches() && blank($batch['number'] ?? null)) {
                throw new PosException("Isi nomor batch untuk {$locked->name}. Tanggal kedaluwarsa boleh dikosongkan kalau barangnya tidak punya.", 'batch_required');
            }

            if ($type === StockMovementType::Opname && $locked->tracksSerials()) {
                throw new PosException("{$locked->name} memakai nomor seri. Hitung unitnya dengan scan IMEI lewat menu Stok Opname, atau catat lewat Stok Masuk/Keluar dengan nomor serinya.", 'serial_required');
            }

            $outletStock = (float) $this->lockStock($locked, $outletId)->stock;

            if ($type === StockMovementType::Opname) {
                if ($quantity < 0) {
                    throw new PosException('Hasil hitung fisik tidak boleh negatif.');
                }

                $delta = round($quantity - $outletStock, 3);

                if ($delta == 0.0) {
                    throw new PosException('Stok fisik sama dengan stok sistem, tidak ada yang perlu disesuaikan.');
                }
            } else {
                if ($quantity <= 0) {
                    throw new PosException('Jumlah harus lebih dari 0.');
                }

                $delta = $type === StockMovementType::StockIn ? $quantity : -$quantity;
            }

            if ($type === StockMovementType::StockIn && $unitCost !== null && $unitCost > 0) {
                $existing = max(0.0, (float) $locked->stock);
                $locked->cost_price = (int) round(($existing * $locked->cost_price + $quantity * $unitCost) / ($existing + $quantity));
            }

            $movement = $this->move($locked, $type, $delta, $user, null, $note, $unitCost, $outletId, $batch);

            if ($locked->tracksSerials()) {
                $type === StockMovementType::StockIn
                    ? $this->serials->receive($locked, $outletId, $batch['serials'] ?? [], $delta)
                    : $this->serials->remove($locked, $outletId, $batch['serials'] ?? [], -$delta);
            }

            ProductChanged::dispatch($locked);

            return $movement;
        });
    }

    /**
     * Opname per batch: $counts adalah hasil hitung fisik tiap batch (product_batch_id => jumlah satuan dasar),
     * $extra batch fisik yang belum tercatat. Stok outlet menjadi jumlah semua hitungan; selisih per batch
     * tercatat di kartu stok sebagai satu mutasi opname.
     *
     * @param  array<int, float|int|string>  $counts
     * @param  array{number: ?string, expires_at: ?string, quantity: float}|null  $extra
     *
     * @throws PosException
     */
    public function opnameBatches(Product $product, array $counts, User $user, ?string $note = null, ?int $outletId = null, ?array $extra = null): StockMovement
    {
        $outletId ??= $this->currentOutletId();
        app(CurrentOutlet::class)->ensureOperational($outletId);
        $this->countGuard->ensureNotHeld([$product->id], $outletId);

        return DB::transaction(function () use ($product, $counts, $user, $note, $outletId, $extra) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if (! $locked->tracksBatches()) {
                throw new PosException("{$locked->name} tidak dilacak per batch.");
            }

            $outletStock = (float) $this->lockStock($locked, $outletId)->stock;
            $this->batches->reconcile($locked, $outletId, $outletStock);
            $batches = ProductBatch::query()->where('product_id', $locked->id)->where('outlet_id', $outletId)->whereIn('id', array_keys($counts))->get()->keyBy('id');
            $delta = 0.0;
            $changed = false;

            foreach ($counts as $batchId => $counted) {
                $batch = $batches->get((int) $batchId) ?? throw new PosException('Batch yang dihitung tidak ditemukan di outlet ini.');

                if ((float) $counted < 0) {
                    throw new PosException('Hasil hitung fisik tidak boleh negatif.');
                }

                $difference = round((float) $counted - (float) $batch->quantity, 3);
                $delta = round($delta + $difference, 3);
                $changed = $changed || $difference != 0.0;
            }

            if ($extra !== null && (float) $extra['quantity'] > 0) {
                $delta = round($delta + (float) $extra['quantity'], 3);
                $changed = true;
            }

            if (! $changed) {
                throw new PosException('Semua batch sama dengan stok sistem, tidak ada yang perlu disesuaikan.');
            }

            $movement = $this->move($locked, StockMovementType::Opname, $delta, $user, null, $note, null, $outletId, ['counts' => $counts, 'extra' => $extra]);
            ProductChanged::dispatch($locked);

            return $movement;
        });
    }

    private function currentOutletId(): int
    {
        return app(CurrentOutlet::class)->idOrPrimary() ?? throw new PosException('Toko belum punya outlet.');
    }
}
