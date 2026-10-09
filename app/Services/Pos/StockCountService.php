<?php

namespace App\Services\Pos;

use App\Enums\StockCountReason;
use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use App\Jobs\PostStockCount;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\ProductUnit;
use App\Models\Scopes\OutletAccessScope;
use App\Models\Setting;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use App\Models\StockCountItem;
use App\Models\StockCountSerial;
use App\Models\StockCountUnknownItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use App\Support\CurrentOutlet;
use App\Support\NumberFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dokumen stok opname: dibuat, dihitung (boleh dicicil dan beramai-ramai), diperiksa, lalu diselesaikan.
 * Stok baru berubah saat diselesaikan, sebesar selisih tiap barang (lihat StockCountVariance).
 */
class StockCountService
{
    public const MAX_ITEMS = 20000;

    public const MAX_QUANTITY = 99999999;

    public const REASON_REQUIRED_ABOVE_KEY = 'inventory.opname.reason_required_above';

    public const ALERT_ABOVE_KEY = 'inventory.opname.alert_above';

    public function __construct(
        private StockService $stock,
        private DocumentNumberGenerator $numbers,
        private StockCountVariance $variance,
        private StockCountPoster $poster,
        private StockCountNotifier $notifier,
    ) {}

    /**
     * @param  list<int>  $categoryIds
     * @param  list<int>  $productIds
     */
    public function start(User $user, int $outletId, StockCountScope $scope, array $categoryIds = [], array $productIds = [], ?bool $blindCount = null, ?bool $holdAdjustments = null, ?string $note = null): StockCount
    {
        $this->authorize($user, 'inventory.opname.manage');
        app(CurrentOutlet::class)->ensureOperational($outletId);

        $categoryIds = $this->ids($categoryIds);
        $productIds = $this->ids($productIds);

        if ($scope === StockCountScope::Categories && $categoryIds === []) {
            throw new PosException('Pilih minimal satu kategori yang mau dihitung.');
        }

        if ($scope === StockCountScope::Products && $productIds === []) {
            throw new PosException('Pilih minimal satu barang yang mau dihitung.');
        }

        return DB::transaction(function () use ($user, $outletId, $scope, $categoryIds, $productIds, $blindCount, $holdAdjustments, $note) {
            // Baris outlet dikunci supaya dua opname yang dimulai bersamaan tidak lolos cek tumpang tindih.
            $outlet = Outlet::query()->whereKey($outletId)->lockForUpdate()->firstOrFail();
            $candidates = $this->candidates($outlet, $scope, $categoryIds, $productIds);
            $this->ensureNoOverlap($outlet, $scope === StockCountScope::All ? null : $candidates);
            $total = (clone $candidates)->count();

            if ($total === 0) {
                throw new PosException('Tidak ada barang yang stoknya dilacak di pilihan ini.', 'stock_count_empty');
            }

            if ($total > self::MAX_ITEMS) {
                throw new PosException('Satu opname maksimal '.NumberFormatter::quantity(self::MAX_ITEMS).' barang. Bagi per kategori supaya lebih ringan.');
            }

            $count = StockCount::query()->create([
                'outlet_id' => $outlet->id,
                'number' => $this->numbers->next('OPN', 4, null, $outlet),
                'status' => StockCountStatus::Counting,
                'scope' => $scope,
                'scope_category_ids' => $scope === StockCountScope::Categories ? $categoryIds : null,
                'blind_count' => $blindCount ?? true,
                'hold_adjustments' => $holdAdjustments ?? $scope === StockCountScope::All,
                'note' => $note,
                'created_by' => $user->id,
                'started_at' => now(),
            ]);

            $this->insertItems($count, $outlet, $candidates);

            activity()->performedOn($count)->causedBy($user)->event('created')
                ->log("Stok opname {$count->number} dimulai ({$total} barang).");

            return $count;
        });
    }

    /**
     * Tambah barang ke dokumen yang berjalan, mis. barang yang di-scan tapi belum masuk lingkup.
     *
     * @param  list<int>  $productIds
     * @return int jumlah barang yang ditambahkan
     */
    public function addProducts(StockCount $count, User $user, array $productIds): int
    {
        $this->authorize($user, 'inventory.opname.count');

        return DB::transaction(function () use ($count, $productIds) {
            $locked = $this->lockOpen($count);

            return $this->addProductsTo($locked, $this->ids($productIds));
        });
    }

    /**
     * Catat satu hitungan. Idempoten lewat client_uuid, supaya antrean offline dan klik ganda tidak dobel.
     * Jumlah boleh dalam satuan lain (unit_id) atau rincian beberapa satuan (breakdown); yang disimpan
     * tetap satuan dasar. Produk ber-batch boleh menyebut batch yang dihitung atau batch baru.
     *
     * @param  array{client_uuid: string, product_id: int, quantity?: float|int|string|null, unit_id?: ?int, breakdown?: ?list<array{unit_id?: ?int, quantity: float|int|string}>, product_batch_id?: ?int, new_batch?: ?array{number?: ?string, expires_at?: ?string}, counted_at?: Carbon|string|null, note?: ?string, source?: string}  $data
     */
    public function recordEntry(StockCount $count, User $user, array $data): StockCountEntry
    {
        $this->authorize($user, 'inventory.opname.count');

        if ($existing = $this->existingEntry($count, $data['client_uuid'])) {
            return $existing;
        }

        $countedAt = isset($data['counted_at']) ? Carbon::parse($data['counted_at']) : now();

        try {
            return DB::transaction(function () use ($count, $user, $data, $countedAt) {
                $locked = $this->lockOpen($count);
                $item = $this->itemFor($locked, (int) $data['product_id']);
                $item = StockCountItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                $product = $item->product;
                [$quantity, $unitId, $breakdown] = $this->baseQuantity($product, $data);
                $batch = $this->countedBatch($product, $locked->outlet_id, $data);

                $entry = $item->entries()->create([
                    'tenant_id' => $item->tenant_id,
                    'user_id' => $user->id,
                    'client_uuid' => $data['client_uuid'],
                    'product_batch_id' => $batch['id'],
                    'new_batch_number' => $batch['number'],
                    'new_batch_expires_at' => $batch['expires_at'],
                    'batch_system_qty' => $batch['system_qty'],
                    'product_unit_id' => $unitId,
                    'breakdown' => $breakdown,
                    'quantity_base' => $quantity,
                    'system_qty_at_count' => $this->systemQtyAt($product, $locked->outlet_id, $countedAt),
                    'counted_at' => $countedAt->min(now()),
                    'source' => $data['source'] ?? StockCountEntry::SOURCE_WEB,
                    'note' => filled($data['note'] ?? null) ? mb_substr(trim($data['note']), 0, 255) : null,
                ]);

                $this->variance->refresh($locked, $item->newCollection([$item]));

                return $entry;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existingEntry($count, $data['client_uuid']) ?? throw $exception;
        }
    }

    /**
     * Catat satu unit bernomor seri yang di-scan. Scan ulang nomor yang sama tidak dobel. Produk boleh tidak
     * disebut; nomor seri yang sudah tercatat menunjukkan produknya sendiri.
     *
     * @param  array{product_id?: ?int, serial: string, scanned_at?: Carbon|string|null}  $data
     */
    public function recordSerial(StockCount $count, User $user, array $data): StockCountSerial
    {
        $this->authorize($user, 'inventory.opname.count');
        $serial = SerialService::normalize([$data['serial'] ?? ''])[0] ?? throw new PosException('Nomor seri kosong.');

        if (mb_strlen($serial) > 64) {
            throw new PosException('Nomor seri terlalu panjang.');
        }

        $scannedAt = isset($data['scanned_at']) ? Carbon::parse($data['scanned_at'])->min(now()) : now();

        return DB::transaction(function () use ($count, $user, $data, $serial, $scannedAt) {
            $locked = $this->lockOpen($count);
            $productId = (int) ($data['product_id'] ?? 0) ?: (int) ProductSerial::query()->where('serial', $serial)->orderByRaw('outlet_id = ? desc', [$locked->outlet_id])->value('product_id');

            if ($productId === 0) {
                throw new PosException("Nomor seri {$serial} belum tercatat. Pilih barangnya dulu supaya bisa didaftarkan.", 'serial_unknown_product', ['serial' => $serial]);
            }

            $item = $this->itemFor($locked, $productId, serial: true);

            if ($existing = $locked->serials()->where('product_id', $productId)->where('serial', $serial)->first()) {
                return $existing;
            }

            $unit = ProductSerial::query()->where('product_id', $productId)->where('serial', $serial)->first();
            $result = match (true) {
                $unit === null => StockCountSerial::RESULT_UNKNOWN,
                $unit->status === ProductSerial::SOLD => StockCountSerial::RESULT_SOLD,
                $unit->status === ProductSerial::REMOVED => StockCountSerial::RESULT_REMOVED,
                $unit->outlet_id !== $locked->outlet_id => StockCountSerial::RESULT_OTHER_OUTLET,
                default => StockCountSerial::RESULT_MATCHED,
            };

            $row = $locked->serials()->create([
                'tenant_id' => $locked->tenant_id,
                'product_id' => $productId,
                'serial' => $serial,
                'result' => $result,
                'user_id' => $user->id,
                'scanned_at' => $scannedAt,
            ]);

            $this->variance->refresh($locked, $item->newCollection([$item]));

            return $row;
        });
    }

    /**
     * Keputusan untuk unit yang tidak cocok: daftarkan sebagai stok, pindahkan catatannya ke outlet ini, atau abaikan.
     */
    public function setSerialAction(StockCountSerial $row, User $user, ?string $action): StockCountSerial
    {
        $this->authorize($user, 'inventory.opname.manage');

        $allowed = match ($row->result) {
            StockCountSerial::RESULT_UNKNOWN, StockCountSerial::RESULT_SOLD, StockCountSerial::RESULT_REMOVED => [StockCountSerial::ACTION_REGISTER, StockCountSerial::ACTION_IGNORE],
            StockCountSerial::RESULT_OTHER_OUTLET => [StockCountSerial::ACTION_RELOCATE, StockCountSerial::ACTION_IGNORE],
            default => [],
        };

        if ($action !== null && ! in_array($action, $allowed, true)) {
            throw new PosException('Tindakan ini tidak bisa dipakai untuk nomor seri tersebut.');
        }

        if ($action === StockCountSerial::ACTION_RELOCATE && ! $user->can('inventory.manage')) {
            throw new PosException('Memindahkan unit dari outlet lain butuh izin kelola stok.', 'forbidden');
        }

        return DB::transaction(function () use ($row, $action) {
            $count = $this->lockOpen($row->stockCount);
            $row->update(['action' => $action]);
            $this->variance->refresh($count, $count->items()->where('product_id', $row->product_id)->get());

            return $row;
        });
    }

    /**
     * Hapus scan nomor seri yang salah.
     */
    public function removeSerial(StockCountSerial $row, User $user): void
    {
        if ($row->user_id !== $user->id) {
            $this->authorize($user, 'inventory.opname.manage');
        }

        DB::transaction(function () use ($row) {
            $count = $this->lockOpen($row->stockCount);
            $row->delete();
            $this->variance->refresh($count, $count->items()->where('product_id', $row->product_id)->get());
        });
    }

    /**
     * Barcode yang tidak dikenal dicatat supaya bisa dibuatkan produk nanti. Stok tidak berubah.
     */
    public function recordUnknown(StockCount $count, User $user, string $barcode, float $quantity = 1, ?string $note = null): StockCountUnknownItem
    {
        $this->authorize($user, 'inventory.opname.count');
        $barcode = mb_substr(trim($barcode), 0, 64);

        if ($barcode === '') {
            throw new PosException('Barcode kosong.');
        }

        if ($quantity <= 0 || $quantity > self::MAX_QUANTITY) {
            throw new PosException('Jumlah harus lebih dari 0.');
        }

        return DB::transaction(function () use ($count, $user, $barcode, $quantity, $note) {
            $locked = $this->lockOpen($count);
            $existing = $locked->unknownItems()->where('barcode', $barcode)->lockForUpdate()->first();

            if ($existing) {
                $existing->update(['quantity' => round((float) $existing->quantity + $quantity, 3), 'note' => filled($note) ? mb_substr(trim($note), 0, 255) : $existing->note]);

                return $existing;
            }

            return $locked->unknownItems()->create([
                'tenant_id' => $locked->tenant_id,
                'barcode' => $barcode,
                'quantity' => round($quantity, 3),
                'note' => filled($note) ? mb_substr(trim($note), 0, 255) : null,
                'user_id' => $user->id,
            ]);
        });
    }

    /**
     * Kenali kode yang di-scan atau diketik: barcode/SKU produk, barcode satuan, atau nomor seri.
     *
     * @return array{kind: 'product'|'serial'|'unknown', product: ?Product, unit: ?ProductUnit, serial: ?string, item: ?StockCountItem}
     */
    public function lookup(StockCount $count, string $code): array
    {
        $code = trim($code);
        $result = ['kind' => 'unknown', 'product' => null, 'unit' => null, 'serial' => null, 'item' => null];

        if ($code === '') {
            return $result;
        }

        $unit = ProductUnit::query()->where('barcode', $code)->whereHas('product')->first();
        $product = $unit?->product
            ?? Product::query()->where('barcode', $code)->first()
            ?? Product::query()->where('sku', $code)->first();

        if ($product === null && ($serial = ProductSerial::query()->where('serial', mb_strtoupper($code))->orderByRaw('outlet_id = ? desc', [$count->outlet_id])->first())) {
            return [...$result, 'kind' => 'serial', 'product' => $serial->product, 'serial' => $serial->serial, 'item' => $count->items()->where('product_id', $serial->product_id)->first()];
        }

        if ($product === null) {
            return $result;
        }

        return [...$result, 'kind' => 'product', 'product' => $product, 'unit' => $unit, 'item' => $count->items()->where('product_id', $product->id)->first()];
    }

    /**
     * Entri yang salah ditandai batal, tidak dihapus. Hanya pemilik entri atau pengelola opname.
     */
    public function voidEntry(StockCountEntry $entry, User $user): StockCountEntry
    {
        if ($entry->user_id !== $user->id) {
            $this->authorize($user, 'inventory.opname.manage');
        }

        return DB::transaction(function () use ($entry, $user) {
            $item = StockCountItem::query()->whereKey($entry->stock_count_item_id)->firstOrFail();
            $count = $this->lockOpen($item->stockCount);
            $item = StockCountItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $locked = StockCountEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($locked->isVoided()) {
                return $locked;
            }

            $locked->forceFill(['voided_at' => now(), 'voided_by' => $user->id])->save();
            $this->variance->refresh($count, $item->newCollection([$item]));

            return $locked;
        });
    }

    /**
     * Pengelola memindahkan dokumen ke tahap Periksa. Penghitung hanya melapor selesai; statusnya tetap.
     */
    public function submit(StockCount $count, User $user): StockCount
    {
        $this->authorize($user, 'inventory.opname.count');

        if (! $user->can('inventory.opname.manage')) {
            $this->ensureEditable($count);
            activity()->performedOn($count)->causedBy($user)->event('updated')->log("Selesai menghitung di stok opname {$count->number}.");
            $this->notifier->submitted($count, $user);

            return $count;
        }

        return DB::transaction(function () use ($count, $user) {
            $locked = $this->lockOpen($count);

            if ($locked->status === StockCountStatus::Counting) {
                $locked->update(['status' => StockCountStatus::Review, 'submitted_by' => $user->id, 'submitted_at' => now()]);
            }

            return $locked;
        });
    }

    public function reopen(StockCount $count, User $user): StockCount
    {
        $this->authorize($user, 'inventory.opname.manage');

        return DB::transaction(function () use ($count) {
            $locked = $this->lockOpen($count);

            if ($locked->status === StockCountStatus::Review) {
                $locked->update(['status' => StockCountStatus::Counting]);
            }

            return $locked;
        });
    }

    public function markRecount(StockCountItem $item, User $user, bool $needsRecount = true): StockCountItem
    {
        $this->authorize($user, 'inventory.opname.manage');
        $this->ensureEditable($item->stockCount);
        $item->update(['needs_recount' => $needsRecount]);

        return $item;
    }

    public function setReason(StockCountItem $item, User $user, ?StockCountReason $reason): StockCountItem
    {
        $this->authorize($user, 'inventory.opname.manage');
        $this->ensureEditable($item->stockCount);
        $item->update(['reason' => $reason]);

        return $item;
    }

    /**
     * Hitung ulang stok sistem acuan dan selisih semua baris tanpa mengubah stok.
     *
     * @return array{items: int, counted: int, changed: int, shortage_qty: float, shortage_value: int, surplus_qty: float, surplus_value: int, uncounted: int, uncounted_qty: float, uncounted_value: int}
     */
    public function preview(StockCount $count): array
    {
        $this->ensureEditable($count);

        $count->items()->with('product')->chunkById(500, fn ($items) => $this->variance->refresh($count, $items));

        return [...$this->variance->summarize($count), ...$this->uncountedStock($count)];
    }

    /**
     * Selesaikan opname. Dokumen kecil langsung diproses; dokumen besar diproses di antrean dan statusnya
     * tetap "posting" sampai selesai.
     */
    public function post(StockCount $count, User $user, string $uncountedPolicy = StockCount::UNCOUNTED_KEEP): StockCount
    {
        $this->authorize($user, 'inventory.opname.manage');

        if (! in_array($uncountedPolicy, [StockCount::UNCOUNTED_KEEP, StockCount::UNCOUNTED_ZERO], true)) {
            throw new PosException('Pilihan untuk barang yang belum dihitung tidak dikenal.');
        }

        app(CurrentOutlet::class)->ensureOperational($count->outlet_id);

        if ($count->status !== StockCountStatus::Review) {
            $this->ensureReviewable($count);
        }

        $this->preview($count);
        $this->ensureReasons($count);

        $locked = DB::transaction(function () use ($count, $user, $uncountedPolicy) {
            $locked = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            $this->ensureReviewable($locked);
            $locked->update(['status' => StockCountStatus::Posting, 'uncounted_policy' => $uncountedPolicy, 'posted_by' => $user->id]);

            return $locked;
        });

        return $this->process($locked, $user);
    }

    /**
     * Lanjutkan dokumen yang tertahan di status "posting", mis. karena server mati di tengah pemrosesan.
     */
    public function resume(StockCount $count, User $user): StockCount
    {
        $this->authorize($user, 'inventory.opname.manage');

        if ($count->status !== StockCountStatus::Posting) {
            throw new PosException("Opname {$count->number} tidak sedang diproses.", 'stock_count_closed');
        }

        return $this->process($count, $user);
    }

    private function process(StockCount $count, User $user): StockCount
    {
        if ($count->items()->whereNull('stock_movement_id')->count() <= StockCountPoster::CHUNK) {
            return $this->poster->run($count, $user);
        }

        PostStockCount::dispatch($count->id, $user->id);

        return $count->fresh();
    }

    public function cancel(StockCount $count, User $user, ?string $reason = null): StockCount
    {
        $this->authorize($user, 'inventory.opname.manage');
        $reason = trim((string) $reason) ?: null;

        return DB::transaction(function () use ($count, $user, $reason): StockCount {
            $locked = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [StockCountStatus::Counting, StockCountStatus::Review], true)) {
                throw new PosException("Opname {$locked->number} sudah {$locked->status->label()}, tidak bisa dibatalkan.", 'stock_count_closed');
            }

            if ($reason === null && $locked->entries()->exists()) {
                throw new PosException('Tulis alasan pembatalan, karena opname ini sudah ada hitungannya.', 'reason_required');
            }

            $locked->update([
                'status' => StockCountStatus::Cancelled,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            activity()->performedOn($locked)->causedBy($user)->event('updated')->log("Stok opname {$locked->number} dibatalkan.");

            return $locked;
        });
    }

    /**
     * Produk yang masuk dokumen: stoknya dilacak dan bukan induk varian (stoknya ada di anak). Produk
     * nonaktif, terhapus, atau tidak dijual di outlet ini tetap masuk bila stoknya di outlet bukan nol.
     *
     * @param  list<int>  $categoryIds
     * @param  list<int>  $productIds
     * @return Builder<Product>
     */
    private function candidates(Outlet $outlet, StockCountScope $scope, array $categoryIds, array $productIds): Builder
    {
        $stockSql = $this->stockSql($outlet);

        return Product::withTrashed()
            ->where('products.track_stock', true)
            ->where(fn (Builder $query) => $query->whereNull('products.variant_options')->orWhere('products.variant_options', '[]'))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->whereNull('products.deleted_at')->where('products.is_active', true)->availableAt($outlet->id))
                ->orWhereRaw("{$stockSql} <> 0"))
            ->when($scope === StockCountScope::Categories, fn (Builder $query) => $query->whereIn('products.category_id', $categoryIds))
            ->when($scope === StockCountScope::Products, fn (Builder $query) => $query->whereIn('products.id', $productIds));
    }

    /**
     * Stok produk di outlet dalam SQL, dengan aturan yang sama dengan StockService::lockStock(): tanpa
     * baris stok, outlet utama mewarisi sisa stok total dan outlet lain nol.
     */
    private function stockSql(Outlet $outlet): string
    {
        $outletId = (int) $outlet->id;
        $fallback = $outlet->is_primary
            ? "(products.stock - COALESCE((SELECT SUM(o.stock) FROM product_stocks o WHERE o.product_id = products.id AND o.outlet_id <> {$outletId}), 0))"
            : '0';

        return "COALESCE((SELECT s.stock FROM product_stocks s WHERE s.product_id = products.id AND s.outlet_id = {$outletId}), {$fallback})";
    }

    /**
     * @param  Builder<Product>  $candidates
     */
    private function insertItems(StockCount $count, Outlet $outlet, Builder $candidates): int
    {
        $now = now();

        return DB::table('stock_count_items')->insertUsing(
            ['tenant_id', 'stock_count_id', 'product_id', 'expected_qty', 'needs_recount', 'created_at', 'updated_at'],
            (clone $candidates)->toBase()
                ->selectRaw("products.tenant_id, ?, products.id, {$this->stockSql($outlet)}, ?, ?, ?", [$count->id, false, $now, $now]),
        );
    }

    /**
     * Satu barang hanya boleh ada di satu opname yang berjalan per outlet. $candidates null berarti
     * semua barang, yang bertabrakan dengan opname apa pun.
     *
     * @param  Builder<Product>|null  $candidates
     */
    private function ensureNoOverlap(Outlet $outlet, ?Builder $candidates, ?StockCount $except = null): void
    {
        $existing = StockCount::query()
            ->withoutGlobalScope(OutletAccessScope::class)
            ->open()
            ->where('outlet_id', $outlet->id)
            ->when($except, fn (Builder $query) => $query->whereKeyNot($except->id))
            ->when($candidates, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('scope', StockCountScope::All)
                ->orWhereHas('items', fn (Builder $items) => $items->whereIn('product_id', (clone $candidates)->select('products.id')->toBase()))))
            ->oldest('id')
            ->first();

        if ($existing) {
            throw new PosException(
                "Sebagian barang sedang dihitung di {$existing->number}. Selesaikan atau batalkan opname itu dulu.",
                'stock_count_overlap',
                ['stock_count_id' => $existing->id, 'number' => $existing->number],
            );
        }
    }

    /**
     * @param  list<int>  $productIds
     */
    private function addProductsTo(StockCount $count, array $productIds): int
    {
        if ($productIds === []) {
            return 0;
        }

        $outlet = Outlet::query()->findOrFail($count->outlet_id);
        $candidates = $this->candidates($outlet, StockCountScope::Products, [], $productIds)
            ->whereNotIn('products.id', $count->items()->select('product_id')->toBase());

        if ((clone $candidates)->doesntExist()) {
            return 0;
        }

        $this->ensureNoOverlap($outlet, $candidates, $count);

        return $this->insertItems($count, $outlet, $candidates);
    }

    private function itemFor(StockCount $count, int $productId, bool $serial = false): StockCountItem
    {
        $item = $count->items()->where('product_id', $productId)->first();
        $product = $item?->product ?? Product::withTrashed()->find($productId) ?? throw new PosException('Barang tidak ditemukan.');

        if (! $product->track_stock || $product->isVariantParent()) {
            throw new PosException("Stok {$product->name} tidak dilacak. Aktifkan \"Lacak stok\" di data produk dulu.", 'stock_count_not_tracked', ['product_id' => $product->id]);
        }

        if ($product->tracksSerials() !== $serial) {
            throw $serial
                ? new PosException("{$product->name} tidak memakai nomor seri. Hitung jumlahnya saja.", 'serial_not_tracked', ['product_id' => $product->id])
                : new PosException("{$product->name} memakai nomor seri. Hitung dengan scan IMEI setiap unit.", 'serial_required', ['product_id' => $product->id]);
        }

        if ($item) {
            return $item;
        }

        if ($count->scope !== StockCountScope::All) {
            throw new PosException("{$product->name} belum masuk opname ini. Tambahkan dulu.", 'stock_count_out_of_scope', ['product_id' => $product->id]);
        }

        $this->addProductsTo($count, [$product->id]);

        return $count->items()->where('product_id', $product->id)->first()
            ?? throw new PosException("{$product->name} tidak bisa dihitung di opname ini.", 'stock_count_out_of_scope', ['product_id' => $product->id]);
    }

    /**
     * Stok sistem saat barang dihitung. Hitungan yang tiba belakangan (antrean HP offline) dimundurkan dengan
     * membuang mutasi yang tercatat sesudah waktu hitungnya.
     */
    private function systemQtyAt(Product $product, int $outletId, Carbon $countedAt): float
    {
        $now = (float) $this->stock->outletStock($product, $outletId);

        if ($countedAt->gte(now()->subMinute())) {
            return $now;
        }

        $since = (float) StockMovement::query()->where('product_id', $product->id)->where('outlet_id', $outletId)->where('created_at', '>', $countedAt)->sum('quantity');

        return round($now - $since, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: float, 1: ?int, 2: ?list<array{unit_id: ?int, name: string, factor: float, quantity: float}>}
     */
    private function baseQuantity(Product $product, array $data): array
    {
        $lines = $data['breakdown'] ?? null;
        $single = ! is_array($lines) || $lines === [];

        if ($single) {
            $lines = [['unit_id' => $data['unit_id'] ?? null, 'quantity' => $data['quantity'] ?? null]];
        }

        $units = ProductUnit::query()->where('product_id', $product->id)
            ->whereIn('id', collect($lines)->pluck('unit_id')->filter()->map(fn ($id) => (int) $id))
            ->get()->keyBy('id');
        $total = 0.0;
        $breakdown = [];

        foreach ($lines as $line) {
            if (! is_numeric($line['quantity'] ?? null)) {
                throw new PosException('Isi jumlah hasil hitung.');
            }

            $amount = round((float) $line['quantity'], 3);
            $unitId = isset($line['unit_id']) ? (int) $line['unit_id'] : null;
            $unit = $unitId ? ($units->get($unitId) ?? throw new PosException("Satuan tidak cocok dengan {$product->name}.")) : null;
            $factor = $unit ? (float) $unit->factor : 1.0;

            if ($amount < 0) {
                throw new PosException('Hasil hitung tidak boleh negatif.');
            }

            $total += $amount * $factor;
            $breakdown[] = ['unit_id' => $unit?->id, 'name' => $unit->name ?? (string) $product->unit, 'factor' => $factor, 'quantity' => $amount];
        }

        $total = round($total, 3);

        if ($total > self::MAX_QUANTITY) {
            throw new PosException('Hasil hitung terlalu besar.');
        }

        if ($single) {
            return [$total, $breakdown[0]['unit_id'], null];
        }

        return [$total, null, $breakdown];
    }

    /**
     * Batch yang dihitung untuk produk ber-batch, beserta saldonya saat itu sebagai acuan selisih per batch.
     *
     * @param  array<string, mixed>  $data
     * @return array{id: ?int, number: ?string, expires_at: ?string, system_qty: ?float}
     */
    private function countedBatch(Product $product, int $outletId, array $data): array
    {
        $none = ['id' => null, 'number' => null, 'expires_at' => null, 'system_qty' => null];

        if (! $product->tracksBatches()) {
            return $none;
        }

        if (! empty($data['product_batch_id'])) {
            $batch = ProductBatch::query()->whereKey((int) $data['product_batch_id'])->where('product_id', $product->id)->where('outlet_id', $outletId)->first()
                ?? throw new PosException('Batch yang dihitung tidak ditemukan di outlet ini.', 'batch_not_found');

            return [...$none, 'id' => $batch->id, 'system_qty' => (float) $batch->quantity];
        }

        $number = trim((string) ($data['new_batch']['number'] ?? ''));

        if ($number === '') {
            return $none;
        }

        $expires = $data['new_batch']['expires_at'] ?? null;

        return [...$none, 'number' => mb_substr($number, 0, 50), 'expires_at' => $expires ? Carbon::parse($expires)->toDateString() : null];
    }

    private function existingEntry(StockCount $count, string $clientUuid): ?StockCountEntry
    {
        $entry = StockCountEntry::query()->with('item')->where('client_uuid', $clientUuid)->first();

        if ($entry && $entry->item->stock_count_id !== $count->id) {
            throw new PosException('Kode hitungan ini sudah dipakai di opname lain.');
        }

        return $entry;
    }

    /**
     * Dokumen dikunci dan dipastikan masih bisa diisi hitungan (sedang dihitung atau diperiksa).
     */
    private function lockOpen(StockCount $count): StockCount
    {
        $locked = StockCount::query()->withoutGlobalScope(OutletAccessScope::class)->whereKey($count->id)->lockForUpdate()->firstOrFail();
        $this->ensureEditable($locked);

        return $locked;
    }

    private function ensureEditable(StockCount $count): void
    {
        if (! in_array($count->status, [StockCountStatus::Counting, StockCountStatus::Review], true)) {
            throw new PosException("Opname {$count->number} sudah {$count->status->label()}, hitungan tidak bisa diubah lagi.", 'stock_count_closed');
        }
    }

    private function ensureReviewable(StockCount $count): void
    {
        if ($count->status === StockCountStatus::Counting) {
            throw new PosException('Lanjutkan ke tahap Periksa dulu sebelum menyelesaikan opname.');
        }

        if ($count->status !== StockCountStatus::Review) {
            throw new PosException("Opname {$count->number} sudah {$count->status->label()}.", 'stock_count_closed');
        }
    }

    private function ensureReasons(StockCount $count): void
    {
        $threshold = (int) Setting::get(self::REASON_REQUIRED_ABOVE_KEY, '0');

        if ($threshold <= 0) {
            return;
        }

        $missing = $count->items()->whereNull('reason')->where('variance_qty', '!=', 0)
            ->whereRaw('ABS(variance_qty * COALESCE(unit_cost, 0)) > ?', [$threshold])
            ->count();

        if ($missing > 0) {
            throw new PosException("{$missing} barang dengan selisih di atas ".NumberFormatter::currency($threshold).' wajib diberi alasan.', 'stock_count_reason_required');
        }
    }

    /**
     * Stok barang yang belum dihitung, untuk konfirmasi pilihan "Anggap habis (0)".
     *
     * @return array{uncounted_qty: float, uncounted_value: int}
     */
    private function uncountedStock(StockCount $count): array
    {
        $row = DB::table('stock_count_items as i')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->leftJoin('product_stocks as s', fn ($join) => $join->on('s.product_id', '=', 'i.product_id')->where('s.outlet_id', $count->outlet_id))
            ->where('i.tenant_id', $count->tenant_id)
            ->where('i.stock_count_id', $count->id)
            ->whereNull('i.counted_qty')
            ->where('s.stock', '>', 0)
            ->selectRaw('SUM(s.stock) as quantity, SUM(s.stock * p.cost_price) as value')
            ->first();

        return ['uncounted_qty' => round((float) $row->quantity, 3), 'uncounted_value' => (int) round((float) $row->value)];
    }

    /**
     * @param  array<mixed>  $ids
     * @return list<int>
     */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
    }

    private function authorize(User $user, string $permission): void
    {
        if (! $user->can($permission)) {
            throw new PosException('Anda tidak punya izin untuk tindakan ini.', 'forbidden');
        }
    }
}
