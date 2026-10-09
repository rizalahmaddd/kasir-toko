<?php

namespace App\Services\Pos;

use App\Enums\StockMovementType;
use App\Events\ProductChanged;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\StockMovementBatch;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use App\Support\CurrentOutlet;
use App\Support\NumberFormatter;
use App\Support\PosSettings;
use Illuminate\Support\Facades\DB;

/**
 * Pemindahan stok antar outlet. Berlaku instan: stok keluar di asal dan masuk di tujuan dalam satu
 * transaksi, jadi products.stock (total semua outlet) tidak berubah.
 */
class StockTransferService
{
    public function __construct(
        private StockService $stock,
        private DocumentNumberGenerator $numbers,
        private SerialService $serials,
        private StockCountGuard $countGuard,
    ) {}

    /**
     * @param  list<array{product_id: int, quantity: float|int|string}>  $items
     */
    public function create(User $user, int $fromOutletId, int $toOutletId, array $items, ?string $note = null): StockTransfer
    {
        if ($fromOutletId === $toOutletId) {
            throw new PosException('Outlet asal dan tujuan tidak boleh sama.');
        }

        $items = $this->mergeItems($items);

        if ($items === []) {
            throw new PosException('Pilih minimal satu produk yang dipindahkan.');
        }

        $outlets = Outlet::query()->whereIn('id', [$fromOutletId, $toOutletId])->get()->keyBy('id');
        $from = $outlets->get($fromOutletId) ?? throw new PosException('Outlet asal tidak ditemukan.');
        $to = $outlets->get($toOutletId) ?? throw new PosException('Outlet tujuan tidak ditemukan.');
        $this->ensureUsable($from, $to);
        $this->countGuard->ensureNotHeld(array_keys($items), $from->id);
        $this->countGuard->ensureNotHeld(array_keys($items), $to->id);

        return DB::transaction(function () use ($user, $from, $to, $items, $note) {
            $products = Product::query()->whereIn('id', array_keys($items))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $transfer = StockTransfer::query()->create([
                'number' => $this->numbers->next('TRF', 4, null, $from),
                'from_outlet_id' => $from->id,
                'to_outlet_id' => $to->id,
                'status' => StockTransfer::STATUS_COMPLETED,
                'created_by' => $user->id,
                'note' => $note,
                'transferred_at' => now(),
            ]);

            $allowNegative = app(CurrentOutlet::class)->run($from->id, fn () => PosSettings::allowNegativeStock());

            foreach ($items as $productId => $quantity) {
                $product = $products->get($productId);

                if (! $product || ! $product->track_stock) {
                    throw new PosException('Hanya produk yang stoknya dilacak yang bisa dipindahkan.');
                }

                $available = (float) $this->stock->lockStock($product, $from->id)->stock;

                if (! $allowNegative && round($available - $quantity, 3) < 0) {
                    throw new PosException("Stok {$product->name} di {$from->name} tidak cukup (tersisa ".NumberFormatter::quantity(max(0, $available))." {$product->unit}).");
                }

                $transfer->items()->create(['product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $product->cost_price]);

                $out = $this->stock->move($product, StockMovementType::TransferOut, -$quantity, $user, $transfer, "{$transfer->number} ke {$to->name}", $product->cost_price, $from->id);
                $this->stock->move($product, StockMovementType::TransferIn, $quantity, $user, $transfer, "{$transfer->number} dari {$from->name}", $product->cost_price, $to->id, $this->lotsFrom($out));

                // Unit bernomor seri ikut pindah, yang paling lama masuk lebih dulu.
                if ($product->tracksSerials()) {
                    $this->serials->transfer($product, $from->id, $to->id, $quantity);
                }

                ProductChanged::dispatch($product);
            }

            activity()->performedOn($transfer)->causedBy($user)->event('created')
                ->log("Transfer stok {$transfer->number} dari {$from->name} ke {$to->name} (".count($items).' produk).');

            return $transfer;
        });
    }

    /**
     * Pembatalan memindahkan stok kembali. Ditolak kalau stok di outlet tujuan sudah terpakai dan
     * tidak cukup lagi, kecuali stok minus diizinkan.
     */
    public function cancel(StockTransfer $transfer, User $user): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user) {
            $locked = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($locked->isCancelled()) {
                throw new PosException('Transfer ini sudah dibatalkan.');
            }

            $from = Outlet::query()->findOrFail($locked->from_outlet_id);
            $to = Outlet::query()->findOrFail($locked->to_outlet_id);
            $this->ensureUsable($from, $to);

            $items = $locked->items()->get();
            $this->countGuard->ensureNotHeld($items->pluck('product_id'), $from->id);
            $this->countGuard->ensureNotHeld($items->pluck('product_id'), $to->id);
            $products = Product::withTrashed()->whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $allowNegative = app(CurrentOutlet::class)->run($to->id, fn () => PosSettings::allowNegativeStock());

            foreach ($items as $item) {
                $product = $products->get($item->product_id);
                $quantity = (float) $item->quantity;

                if (! $product) {
                    continue;
                }

                $available = (float) $this->stock->lockStock($product, $to->id)->stock;

                if (! $allowNegative && round($available - $quantity, 3) < 0) {
                    throw new PosException("Transfer tidak bisa dibatalkan: stok {$product->name} di {$to->name} sudah tidak cukup (tersisa ".NumberFormatter::quantity(max(0, $available))." {$product->unit}).");
                }

                $out = $this->stock->move($product, StockMovementType::TransferOut, -$quantity, $user, $locked, "Batal {$locked->number}", $item->unit_cost, $to->id);
                $this->stock->move($product, StockMovementType::TransferIn, $quantity, $user, $locked, "Batal {$locked->number}", $item->unit_cost, $from->id, $this->lotsFrom($out));

                if ($product->tracksSerials()) {
                    $this->serials->transfer($product, $to->id, $from->id, $quantity);
                }
            }

            $locked->update([
                'status' => StockTransfer::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
            ]);

            activity()->performedOn($locked)->causedBy($user)->event('updated')->log("Transfer stok {$locked->number} dibatalkan.");

            return $locked;
        });
    }

    /**
     * Batch yang keluar dari outlet asal dibuat ulang di tujuan dengan nomor & tanggal kedaluwarsa yang sama.
     *
     * @return array{lots?: list<array{number: ?string, expires_at: ?string, quantity: float, unit_cost: ?int}>}
     */
    private function lotsFrom(StockMovement $out): array
    {
        if ($out->batchLines->isEmpty()) {
            return [];
        }

        $batches = ProductBatch::query()->whereIn('id', $out->batchLines->pluck('product_batch_id'))->get()->keyBy('id');

        return ['lots' => $out->batchLines->map(fn (StockMovementBatch $line) => [
            'number' => $batches->get($line->product_batch_id)?->batch_number,
            'expires_at' => $batches->get($line->product_batch_id)?->expires_at?->toDateString(),
            'quantity' => abs((float) $line->quantity),
            'unit_cost' => $batches->get($line->product_batch_id)?->unit_cost,
        ])->values()->all()];
    }

    /**
     * @param  list<array{product_id: int, quantity: float|int|string}>  $items
     * @return array<int, float> product_id => jumlah
     */
    private function mergeItems(array $items): array
    {
        $merged = [];

        foreach ($items as $item) {
            $quantity = round((float) $item['quantity'], 3);

            if ($quantity <= 0) {
                throw new PosException('Jumlah yang dipindahkan harus lebih dari 0.');
            }

            $merged[(int) $item['product_id']] = round(($merged[(int) $item['product_id']] ?? 0) + $quantity, 3);
        }

        return $merged;
    }

    /**
     * Kedua outlet harus bisa diakses user dan tidak terkunci batas paket.
     */
    private function ensureUsable(Outlet $from, Outlet $to): void
    {
        $current = app(CurrentOutlet::class);

        foreach ([$from, $to] as $outlet) {
            if ($current->hasAccessLoaded() && ! $current->canAccess($outlet->id)) {
                throw new PosException("Anda tidak punya akses ke outlet {$outlet->name}.", 'outlet_forbidden');
            }

            $current->ensureOperational($outlet->id);
        }
    }
}
