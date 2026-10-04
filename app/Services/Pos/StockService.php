<?php

namespace App\Services\Pos;

use App\Enums\StockMovementType;
use App\Events\ProductChanged;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockAlertNotifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * Mutasi stok untuk produk yang sudah dikunci (lockForUpdate) oleh pemanggil. $quantity bertanda:
     * positif menambah, negatif mengurangi.
     */
    public function move(Product $product, StockMovementType $type, float $quantity, ?User $user, ?Model $reference = null, ?string $note = null, ?int $unitCost = null): StockMovement
    {
        $before = (float) $product->stock;
        $after = round($before + $quantity, 3);

        // Kartu stok (stock_movements) sudah jadi jejaknya; save biasa akan menulis audit log dan
        // broadcast untuk setiap barang di setiap transaksi.
        $product->stock = $after;
        $product->saveQuietly();

        app(StockAlertNotifier::class)->notifyIfNeeded($product, $before, $after);

        return StockMovement::create([
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
        ]);
    }

    /**
     * Penyesuaian manual dari halaman Stok. Opname: $quantity adalah hasil hitung fisik, bukan selisih.
     * Stok masuk dengan harga beli memperbarui HPP dengan rata-rata tertimbang.
     */
    public function adjust(Product $product, StockMovementType $type, float $quantity, User $user, ?string $note = null, ?int $unitCost = null): StockMovement
    {
        if (! in_array($type, [StockMovementType::StockIn, StockMovementType::StockOut, StockMovementType::Opname], true)) {
            throw new PosException('Jenis penyesuaian stok tidak dikenal.');
        }

        return DB::transaction(function () use ($product, $type, $quantity, $user, $note, $unitCost) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if (! $locked->track_stock) {
                throw new PosException("Stok {$locked->name} tidak dilacak. Aktifkan \"Lacak stok\" di data produk dulu.");
            }

            if ($type === StockMovementType::Opname) {
                if ($quantity < 0) {
                    throw new PosException('Hasil hitung fisik tidak boleh negatif.');
                }

                $delta = round($quantity - (float) $locked->stock, 3);

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

            $movement = $this->move($locked, $type, $delta, $user, null, $note, $unitCost);
            ProductChanged::dispatch($locked);

            return $movement;
        });
    }
}
