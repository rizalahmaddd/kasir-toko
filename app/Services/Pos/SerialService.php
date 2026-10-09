<?php

namespace App\Services\Pos;

use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\SaleItem;
use Illuminate\Support\Collection;

/**
 * Nomor seri/IMEI per unit. Dipanggil di dalam transaksi pemanggil (stok masuk, penjualan, pembatalan,
 * transfer) yang sudah mengunci produknya, jadi status unit dan stok outlet berubah bersamaan.
 */
class SerialService
{
    /**
     * @param  list<string>|null  $serials
     * @return list<string>
     */
    public static function normalize(?array $serials): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($serial) => mb_strtoupper(trim((string) $serial)), $serials ?? []), fn (string $serial) => $serial !== '')));
    }

    /**
     * Stok masuk: tiap unit baru wajib bernomor seri unik. Nomor yang pernah dikeluarkan (removed) boleh masuk lagi.
     *
     * @param  list<string>  $serials
     *
     * @throws PosException
     */
    public function receive(Product $product, int $outletId, array $serials, float $quantity): void
    {
        $serials = self::normalize($serials);
        $this->ensureCount($product, $serials, $quantity);

        $existing = ProductSerial::query()->where('product_id', $product->id)->whereIn('serial', $serials)->get()->keyBy('serial');
        $taken = $existing->reject(fn (ProductSerial $serial) => $serial->status === ProductSerial::REMOVED)->keys();

        if ($taken->isNotEmpty()) {
            throw new PosException("Nomor seri {$taken->take(3)->implode(', ')} sudah tercatat untuk {$product->name}.", 'serial_taken', ['serials' => $taken->values()->all()]);
        }

        foreach ($serials as $serial) {
            if ($row = $existing->get($serial)) {
                $row->update(['outlet_id' => $outletId, 'status' => ProductSerial::IN_STOCK, 'sale_item_id' => null, 'sold_at' => null]);
            } else {
                ProductSerial::query()->create(['product_id' => $product->id, 'outlet_id' => $outletId, 'serial' => $serial, 'status' => ProductSerial::IN_STOCK]);
            }
        }
    }

    /**
     * Daftarkan nomor seri untuk stok yang sudah ada sebelum produk memakai nomor seri; stoknya tidak berubah.
     *
     * @param  list<string>  $serials
     *
     * @throws PosException
     */
    public function register(Product $product, int $outletId, array $serials, float $outletStock): int
    {
        $serials = self::normalize($serials);
        $registered = ProductSerial::query()->where('product_id', $product->id)->where('outlet_id', $outletId)->available()->count();
        $room = (int) floor($outletStock) - $registered;

        if ($serials === []) {
            throw new PosException('Isi minimal satu nomor seri.', 'serial_required');
        }

        if (count($serials) > $room) {
            throw new PosException("Stok {$product->name} di outlet ini yang belum bernomor seri tinggal {$room} unit.", 'serial_required');
        }

        $this->receive($product, $outletId, $serials, count($serials));

        return count($serials);
    }

    /**
     * Stok keluar non-penjualan (rusak, hilang, retur ke pemasok): unit yang disebut ditandai keluar.
     *
     * @param  list<string>  $serials
     *
     * @throws PosException
     */
    public function remove(Product $product, int $outletId, array $serials, float $quantity): void
    {
        $serials = self::normalize($serials);
        $this->ensureCount($product, $serials, $quantity);
        $units = $this->inStock($product, $outletId, $serials);

        $units->each->update(['status' => ProductSerial::REMOVED]);
    }

    /**
     * Penjualan. Online: setiap unit harus dipilih dan masih ada di outlet ini. Offline: unit yang tidak
     * dikenal tetap dicatat terjual (transaksinya sudah terjadi) dan transaksi ditandai.
     *
     * @param  list<string>  $serials
     * @return bool true kalau semua nomor seri cocok dengan stok
     *
     * @throws PosException
     */
    public function sell(Product $product, int $outletId, array $serials, float $quantity, SaleItem $item, bool $offline): bool
    {
        $serials = self::normalize($serials);

        if (! $offline) {
            $this->ensureCount($product, $serials, $quantity);
            $this->inStock($product, $outletId, $serials)->each->update(['status' => ProductSerial::SOLD, 'sale_item_id' => $item->id, 'sold_at' => now()]);

            return true;
        }

        $known = ProductSerial::query()->where('product_id', $product->id)->whereIn('serial', $serials)->get()->keyBy('serial');
        $verified = count($serials) === (int) round($quantity);

        foreach ($serials as $serial) {
            $row = $known->get($serial);

            if ($row && $row->status === ProductSerial::IN_STOCK) {
                $row->update(['status' => ProductSerial::SOLD, 'sale_item_id' => $item->id, 'sold_at' => now()]);

                continue;
            }

            $verified = false;

            if (! $row) {
                ProductSerial::query()->create(['product_id' => $product->id, 'outlet_id' => $outletId, 'serial' => $serial, 'status' => ProductSerial::SOLD, 'sale_item_id' => $item->id, 'sold_at' => now()]);
            }
        }

        return $verified;
    }

    /**
     * Pembatalan penjualan: unitnya kembali ke stok outlet transaksi.
     */
    public function restore(SaleItem $item, int $outletId): void
    {
        ProductSerial::query()->where('sale_item_id', $item->id)->where('status', ProductSerial::SOLD)
            ->get()
            ->each->update(['status' => ProductSerial::IN_STOCK, 'outlet_id' => $outletId, 'sale_item_id' => null, 'sold_at' => null]);
    }

    /**
     * Transfer: unit yang disebut (atau yang paling lama masuk bila tidak disebut) pindah outlet.
     *
     * @param  list<string>  $serials
     *
     * @throws PosException
     */
    public function transfer(Product $product, int $fromOutletId, int $toOutletId, float $quantity, array $serials = []): void
    {
        $serials = self::normalize($serials);
        $count = (int) round($quantity);

        $units = $serials !== []
            ? $this->inStock($product, $fromOutletId, $serials)
            : ProductSerial::query()->where('product_id', $product->id)->where('outlet_id', $fromOutletId)->available()->orderBy('id')->limit($count)->get();

        if ($units->count() < $count) {
            throw new PosException("Nomor seri {$product->name} di outlet asal hanya {$units->count()} unit. Daftarkan nomor serinya dulu.", 'serial_missing');
        }

        $units->take($count)->each->update(['outlet_id' => $toOutletId]);
    }

    /**
     * @param  list<string>  $serials
     *
     * @throws PosException
     */
    private function ensureCount(Product $product, array $serials, float $quantity): void
    {
        if (abs($quantity - round($quantity)) > 0.0001) {
            throw new PosException("{$product->name} memakai nomor seri, jadi jumlahnya harus bilangan bulat.", 'serial_required');
        }

        if (count($serials) !== (int) round($quantity)) {
            throw new PosException("Isi {$this->units($quantity)} nomor seri untuk {$product->name} (baru ".count($serials).').', 'serial_required', ['product_id' => $product->id]);
        }
    }

    /**
     * @param  list<string>  $serials
     * @return Collection<int, ProductSerial>
     *
     * @throws PosException
     */
    private function inStock(Product $product, int $outletId, array $serials): Collection
    {
        $units = ProductSerial::query()->where('product_id', $product->id)->where('outlet_id', $outletId)->available()->whereIn('serial', $serials)->get();
        $missing = array_values(array_diff($serials, $units->pluck('serial')->all()));

        if ($missing !== []) {
            throw new PosException('Nomor seri '.implode(', ', array_slice($missing, 0, 3))." tidak ada di stok {$product->name} outlet ini.", 'serial_unavailable', ['serials' => $missing]);
        }

        return $units;
    }

    private function units(float $quantity): string
    {
        return (string) (int) round($quantity);
    }
}
