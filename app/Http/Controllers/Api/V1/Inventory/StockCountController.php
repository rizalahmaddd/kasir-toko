<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Enums\StockCountReason;
use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use App\Events\StockCountUpdated;
use App\Http\Controllers\Api\V1\Controller;
use App\Http\Resources\V1\Inventory\StockCountEntryResource;
use App\Http\Resources\V1\Inventory\StockCountItemResource;
use App\Http\Resources\V1\Inventory\StockCountResource;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use App\Models\StockCountItem;
use App\Services\Pos\PosException;
use App\Services\Pos\StockCountService;
use App\Support\CurrentOutlet;
use App\Support\DeviceClock;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

#[ApiTag('Stok Opname', 'Stok', 'Dokumen stok opname per outlet: dibuat, dihitung (boleh banyak orang dan offline), diperiksa, lalu diselesaikan. Stok baru berubah saat diselesaikan, sebesar selisih tiap barang. Mengisi hitungan butuh izin `inventory.opname.count`; memulai, memeriksa, menyelesaikan, dan membatalkan butuh `inventory.opname.manage`.')]
class StockCountController extends Controller
{
    /**
     * Daftar opname.
     *
     * Opname di outlet aktif, terbaru dulu.
     */
    #[ApiQuery('status', description: '`open` untuk yang masih berjalan, atau satu status.', enum: ['open', 'counting', 'review', 'posting', 'posted', 'cancelled'])]
    #[ApiQuery('outlet_id', type: 'integer', description: 'Outlet lain yang boleh diakses; bawaannya outlet aktif (header `X-Outlet-Id`).')]
    #[ApiResponse(StockCountResource::class, paginated: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensureCan($request, 'inventory.opname.count');
        $status = (string) $request->query('status');

        $counts = StockCount::query()
            ->with(['outlet', 'creator'])
            ->withCount(['items', 'items as counted_items_count' => fn (Builder $query) => $query->whereNotNull('counted_qty')])
            ->when($status === 'open', fn (Builder $query) => $query->open())
            ->when(StockCountStatus::tryFrom($status), fn (Builder $query, StockCountStatus $value) => $query->where('status', $value))
            ->where('outlet_id', $request->integer('outlet_id') ?: app(CurrentOutlet::class)->idOrPrimary())
            ->latest('id')
            ->paginate($this->perPage($request));

        return StockCountResource::collection($counts);
    }

    /**
     * Mulai opname.
     *
     * Di outlet aktif (header `X-Outlet-Id`). 422 `stock_count_overlap` bila sebagian barang sedang dihitung
     * di opname lain; 423 bila outlet terkunci paket.
     */
    #[ApiResponse(StockCountResource::class, status: 201)]
    public function store(Request $request, StockCountService $counts): JsonResponse
    {
        $this->ensureCan($request, 'inventory.opname.manage');

        $data = $request->validate([
            'scope' => ['required', Rule::enum(StockCountScope::class)],
            'category_ids' => ['nullable', 'array', 'max:500'],
            'category_ids.*' => ['integer'],
            'product_ids' => ['nullable', 'array', 'max:5000'],
            'product_ids.*' => ['integer'],
            'blind_count' => ['nullable', 'boolean'],
            'hold_adjustments' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $outletId = app(CurrentOutlet::class)->idOrPrimary() ?? abort(422, 'Toko belum punya outlet.');
        $count = $counts->start(
            $request->user(),
            $outletId,
            StockCountScope::from($data['scope']),
            $data['category_ids'] ?? [],
            $data['product_ids'] ?? [],
            $data['blind_count'] ?? null,
            $data['hold_adjustments'] ?? null,
            ($data['note'] ?? null) ?: null,
        );

        return $this->created(new StockCountResource($this->loaded($count)));
    }

    /**
     * Detail opname.
     */
    #[ApiResponse(StockCountResource::class)]
    public function show(Request $request, StockCount $stockCount): StockCountResource
    {
        $this->ensureCan($request, 'inventory.opname.count');

        return new StockCountResource($this->loaded($stockCount));
    }

    /**
     * Barang di opname.
     *
     * `filter`: `uncounted` belum dihitung, `counted` sudah, `variance` ada selisih (hanya bila boleh melihat
     * stok sistem), `recount` ditandai hitung ulang.
     */
    #[ApiQuery('filter', description: 'Saring barang.', enum: ['uncounted', 'counted', 'variance', 'recount'])]
    #[ApiQuery('search', description: 'Nama, SKU, atau barcode.')]
    #[ApiQuery('category_id', type: 'integer', description: 'Filter kategori.')]
    #[ApiResponse(StockCountItemResource::class, paginated: true)]
    public function items(Request $request, StockCount $stockCount): AnonymousResourceCollection
    {
        $this->ensureCan($request, 'inventory.opname.count');
        $search = trim((string) $request->query('search'));
        $filter = (string) $request->query('filter');
        $canSeeSystem = $request->user()->can('inventory.opname.manage') || ! $stockCount->blind_count;

        $items = $stockCount->items()
            ->with($this->itemRelations($stockCount))
            ->join('products', 'products.id', '=', 'stock_count_items.product_id')
            ->select('stock_count_items.*')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('products.name', 'like', "%{$search}%")
                ->orWhere('products.sku', 'like', "%{$search}%")
                ->orWhere('products.barcode', $search)))
            ->when($request->integer('category_id'), fn (Builder $query, int $categoryId) => $query->where('products.category_id', $categoryId))
            ->when($filter === 'uncounted', fn (Builder $query) => $query->whereNull('stock_count_items.counted_qty'))
            ->when($filter === 'counted', fn (Builder $query) => $query->whereNotNull('stock_count_items.counted_qty'))
            ->when($filter === 'variance' && $canSeeSystem, fn (Builder $query) => $query->where('stock_count_items.variance_qty', '!=', 0))
            ->when($filter === 'recount', fn (Builder $query) => $query->where('stock_count_items.needs_recount', true))
            ->orderBy('products.name')
            ->paginate($this->perPage($request, 50));

        $items->getCollection()->each->setRelation('stockCount', $stockCount);

        return StockCountItemResource::collection($items);
    }

    /**
     * Katalog untuk scan offline.
     *
     * Semua barang di opname dalam bentuk ringkas (tanpa angka stok), untuk disimpan di HP supaya scan tetap
     * jalan tanpa sinyal: `[item_id, product_id, nama, sku, barcode, satuan, track_batch, track_serial, satuan lain]`.
     */
    public function catalog(Request $request, StockCount $stockCount): JsonResponse
    {
        $this->ensureCan($request, 'inventory.opname.count');

        $rows = $stockCount->items()->with('product.units')->get()->map(fn (StockCountItem $item) => [
            'item_id' => $item->id,
            'product_id' => $item->product_id,
            'name' => $item->product->name.($item->product->variantLabel() ? ' — '.$item->product->variantLabel() : ''),
            'sku' => $item->product->sku,
            'barcode' => $item->product->barcode,
            'unit' => $item->product->unit,
            'track_batch' => $item->product->tracksBatches(),
            'track_serial' => $item->product->tracksSerials(),
            'units' => $item->product->units->map(fn ($unit) => ['id' => $unit->id, 'name' => $unit->name, 'factor' => (float) $unit->factor, 'barcode' => $unit->barcode])->values()->all(),
        ]);

        return response()->json(['data' => $rows->values()->all(), 'scope' => $stockCount->scope->value]);
    }

    /**
     * Tambah barang ke opname.
     */
    public function addItems(Request $request, StockCount $stockCount, StockCountService $counts): JsonResponse
    {
        $this->ensureCan($request, 'inventory.opname.count');
        $this->ensureActiveOutlet($stockCount);
        $data = $request->validate(['product_ids' => ['required', 'array', 'min:1', 'max:500'], 'product_ids.*' => ['integer']]);

        $added = $counts->addProducts($stockCount, $request->user(), $data['product_ids']);
        StockCountUpdated::dispatch($stockCount);

        return response()->json(['added' => $added]);
    }

    /**
     * Kenali kode.
     *
     * Barcode/SKU produk, barcode satuan (mis. dus), atau nomor seri. `kind` `unknown` berarti tidak dikenal
     * dan bisa dicatat lewat endpoint `unknown`.
     */
    #[ApiQuery('code', required: true, description: 'Kode yang di-scan atau diketik.')]
    public function lookup(Request $request, StockCount $stockCount, StockCountService $counts): JsonResponse
    {
        $this->ensureCan($request, 'inventory.opname.count');
        $found = $counts->lookup($stockCount, (string) $request->query('code'));
        $item = $found['item'];

        if ($item) {
            $item->load($this->itemRelations($stockCount))->setRelation('stockCount', $stockCount);
        }

        return response()->json([
            'kind' => $found['kind'],
            'serial' => $found['serial'],
            'unit_id' => $found['unit']?->id,
            'product' => $found['product'] ? [
                'id' => $found['product']->id,
                'name' => $found['product']->name,
                'track_stock' => (bool) $found['product']->track_stock,
                'track_serial' => $found['product']->tracksSerials(),
            ] : null,
            'item' => $item ? new StockCountItemResource($item) : null,
        ]);
    }

    /**
     * Kirim hitungan.
     *
     * Banyak entri sekaligus (maks. 500) untuk antrean offline. Idempoten per `client_uuid`. Hasil per entri:
     * `saved`, `duplicate` (sudah pernah diterima), atau `rejected` beserta `reason` (mis. `stock_count_closed`
     * berarti opname sudah ditutup dan entri ini dibuang). `counted_at` adalah jam HP saat menghitung; jam HP
     * dikoreksi memakai `device_sent_at`.
     */
    public function storeEntries(Request $request, StockCount $stockCount, StockCountService $counts): JsonResponse
    {
        $this->ensureCan($request, 'inventory.opname.count');

        $data = $request->validate([
            'device_sent_at' => ['nullable', 'date'],
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.client_uuid' => ['required', 'uuid'],
            'entries.*.product_id' => ['required', 'integer'],
            'entries.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'entries.*.unit_id' => ['nullable', 'integer'],
            'entries.*.breakdown' => ['nullable', 'array', 'max:10'],
            'entries.*.breakdown.*.unit_id' => ['nullable', 'integer'],
            'entries.*.breakdown.*.quantity' => ['required_with:entries.*.breakdown', 'numeric', 'min:0'],
            'entries.*.product_batch_id' => ['nullable', 'integer'],
            'entries.*.new_batch' => ['nullable', 'array'],
            'entries.*.new_batch.number' => ['nullable', 'string', 'max:50'],
            'entries.*.new_batch.expires_at' => ['nullable', 'date'],
            'entries.*.counted_at' => ['nullable', 'date'],
            'entries.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $results = [];
        ksort($data['entries']);

        foreach ($data['entries'] as $entry) {
            if ($rejection = $this->otherOutletRejection($stockCount, $entry['client_uuid'])) {
                $results[] = $rejection;

                continue;
            }

            $existing = StockCountEntry::query()->where('client_uuid', $entry['client_uuid'])->exists();

            try {
                $saved = $counts->recordEntry($stockCount, $request->user(), [
                    ...$entry,
                    'counted_at' => DeviceClock::correct($entry['counted_at'] ?? null, $data['device_sent_at'] ?? null) ?? now(),
                    'source' => StockCountEntry::SOURCE_MOBILE,
                ]);
                $results[] = ['client_uuid' => $entry['client_uuid'], 'status' => $existing ? 'duplicate' : 'saved', 'entry_id' => $saved->id, 'item_id' => $saved->stock_count_item_id];
            } catch (PosException $exception) {
                $results[] = ['client_uuid' => $entry['client_uuid'], 'status' => 'rejected', 'reason' => $exception->reason, 'error' => $exception->getMessage(), 'context' => (object) $exception->context];
            }
        }

        StockCountUpdated::dispatch($stockCount);

        return response()->json(['data' => $results]);
    }

    /**
     * Riwayat hitungan satu barang.
     */
    #[ApiResponse(StockCountEntryResource::class, collection: true)]
    public function entries(Request $request, StockCount $stockCount, StockCountItem $item): AnonymousResourceCollection
    {
        $this->ensureCan($request, 'inventory.opname.count');
        abort_unless($item->stock_count_id === $stockCount->id, 404);

        return StockCountEntryResource::collection($item->entries()->with('user')->latest('counted_at')->get());
    }

    /**
     * Batalkan hitungan.
     *
     * Hanya pemilik entri atau pengelola opname. Entri ditandai batal, tidak dihapus.
     */
    public function voidEntry(Request $request, StockCount $stockCount, StockCountEntry $entry, StockCountService $counts): StockCountEntryResource
    {
        $this->ensureCan($request, 'inventory.opname.count');
        $this->ensureActiveOutlet($stockCount);
        abort_unless($entry->item?->stock_count_id === $stockCount->id, 404);

        $voided = $counts->voidEntry($entry, $request->user());
        StockCountUpdated::dispatch($stockCount);

        return new StockCountEntryResource($voided->load('user'));
    }

    /**
     * Kirim scan nomor seri.
     *
     * `result` per nomor: `matched` (cocok), `unknown`, `other_outlet`, `sold`, `removed`. Scan ulang nomor
     * yang sama tidak dobel.
     */
    public function storeSerials(Request $request, StockCount $stockCount, StockCountService $counts): JsonResponse
    {
        $this->ensureCan($request, 'inventory.opname.count');

        $data = $request->validate([
            'device_sent_at' => ['nullable', 'date'],
            'serials' => ['required', 'array', 'min:1', 'max:500'],
            'serials.*.client_uuid' => ['nullable', 'string', 'max:64'],
            'serials.*.product_id' => ['nullable', 'integer'],
            'serials.*.serial' => ['required', 'string', 'max:64'],
            'serials.*.scanned_at' => ['nullable', 'date'],
        ]);

        $results = [];
        // Validasi menyusun ulang array bila kolom opsional hanya ada di sebagian baris; urutan scan harus tetap.
        ksort($data['serials']);

        foreach ($data['serials'] as $scan) {
            if ($rejection = $this->otherOutletRejection($stockCount, $scan['client_uuid'] ?? null)) {
                $results[] = [...$rejection, 'serial' => $scan['serial']];

                continue;
            }

            try {
                $row = $counts->recordSerial($stockCount, $request->user(), [
                    ...$scan,
                    'scanned_at' => DeviceClock::correct($scan['scanned_at'] ?? null, $data['device_sent_at'] ?? null) ?? now(),
                ]);
                $results[] = ['client_uuid' => $scan['client_uuid'] ?? null, 'serial' => $row->serial, 'status' => 'saved', 'result' => $row->result, 'product_id' => $row->product_id];
            } catch (PosException $exception) {
                $results[] = ['client_uuid' => $scan['client_uuid'] ?? null, 'serial' => $scan['serial'], 'status' => 'rejected', 'reason' => $exception->reason, 'error' => $exception->getMessage()];
            }
        }

        StockCountUpdated::dispatch($stockCount);

        return response()->json(['data' => $results]);
    }

    /**
     * Catat barang tak dikenal.
     *
     * Barcode yang tidak dikenal dicatat untuk dibuatkan produk nanti. Stok tidak berubah.
     */
    public function storeUnknown(Request $request, StockCount $stockCount, StockCountService $counts): JsonResponse
    {
        $this->ensureCan($request, 'inventory.opname.count');
        $this->ensureActiveOutlet($stockCount);
        $data = $request->validate([
            'barcode' => ['required', 'string', 'max:64'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $row = $counts->recordUnknown($stockCount, $request->user(), $data['barcode'], (float) $data['quantity'], $data['note'] ?? null);

        return response()->json(['data' => ['id' => $row->id, 'barcode' => $row->barcode, 'quantity' => (float) $row->quantity, 'note' => $row->note]], 201);
    }

    /**
     * Selesai menghitung / lanjut ke Periksa.
     *
     * Pengelola opname memindahkan dokumen ke tahap Periksa. Penghitung hanya memberi tahu pengelola; status tetap.
     */
    #[ApiResponse(StockCountResource::class)]
    public function submit(Request $request, StockCount $stockCount, StockCountService $counts): StockCountResource
    {
        $this->ensureCan($request, 'inventory.opname.count');
        $this->ensureActiveOutlet($stockCount);
        $count = $counts->submit($stockCount, $request->user());
        StockCountUpdated::dispatch($count);

        return new StockCountResource($this->loaded($count));
    }

    /**
     * Kembali menghitung.
     */
    #[ApiResponse(StockCountResource::class)]
    public function reopen(Request $request, StockCount $stockCount, StockCountService $counts): StockCountResource
    {
        $this->ensureCan($request, 'inventory.opname.manage');
        $this->ensureActiveOutlet($stockCount);
        $count = $counts->reopen($stockCount, $request->user());
        StockCountUpdated::dispatch($count);

        return new StockCountResource($this->loaded($count));
    }

    /**
     * Pratinjau dampak.
     *
     * Hitung ulang selisih semua barang tanpa mengubah stok: jumlah barang berubah, nilai kurang/lebih, dan
     * stok barang yang belum dihitung (untuk pilihan "anggap habis").
     */
    public function preview(Request $request, StockCount $stockCount, StockCountService $counts): JsonResponse
    {
        $this->ensureCan($request, 'inventory.opname.manage');

        return response()->json(['data' => $counts->preview($stockCount)]);
    }

    /**
     * Ubah baris.
     *
     * Alasan selisih dan tanda hitung ulang.
     */
    #[ApiResponse(StockCountItemResource::class)]
    public function updateItem(Request $request, StockCount $stockCount, StockCountItem $item, StockCountService $counts): StockCountItemResource
    {
        $this->ensureCan($request, 'inventory.opname.manage');
        $this->ensureActiveOutlet($stockCount);
        abort_unless($item->stock_count_id === $stockCount->id, 404);

        $data = $request->validate([
            'reason' => ['nullable', Rule::enum(StockCountReason::class)],
            'needs_recount' => ['nullable', 'boolean'],
        ]);

        if ($request->has('reason')) {
            $counts->setReason($item, $request->user(), isset($data['reason']) ? StockCountReason::from($data['reason']) : null);
        }

        if (array_key_exists('needs_recount', $data) && $data['needs_recount'] !== null) {
            $counts->markRecount($item, $request->user(), (bool) $data['needs_recount']);
        }

        return new StockCountItemResource($item->fresh()->load($this->itemRelations($stockCount))->setRelation('stockCount', $stockCount));
    }

    /**
     * Selesaikan opname.
     *
     * Stok ditambah/dikurangi sebesar selisih tiap barang. `uncounted_policy`: `keep` (bawaan, stok barang
     * yang belum dihitung dibiarkan) atau `zero` (dianggap habis). Opname besar diproses di antrean; statusnya
     * `posting` sampai selesai.
     */
    #[ApiResponse(StockCountResource::class)]
    public function post(Request $request, StockCount $stockCount, StockCountService $counts): StockCountResource
    {
        $this->ensureCan($request, 'inventory.opname.manage');
        $this->ensureActiveOutlet($stockCount);
        $data = $request->validate(['uncounted_policy' => ['nullable', Rule::in([StockCount::UNCOUNTED_KEEP, StockCount::UNCOUNTED_ZERO])]]);

        $count = $counts->post($stockCount, $request->user(), $data['uncounted_policy'] ?? StockCount::UNCOUNTED_KEEP);

        return new StockCountResource($this->loaded($count));
    }

    /**
     * Batalkan opname.
     *
     * Stok tidak berubah. Alasan wajib bila sudah ada hitungan.
     */
    #[ApiResponse(StockCountResource::class)]
    public function cancel(Request $request, StockCount $stockCount, StockCountService $counts): StockCountResource
    {
        $this->ensureCan($request, 'inventory.opname.manage');
        $this->ensureActiveOutlet($stockCount);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $count = $counts->cancel($stockCount, $request->user(), $data['reason'] ?? null);
        StockCountUpdated::dispatch($count);

        return new StockCountResource($this->loaded($count));
    }

    private function loaded(StockCount $count): StockCount
    {
        return $count->fresh(['outlet', 'creator'])->loadCount(['items', 'items as counted_items_count' => fn (Builder $query) => $query->whereNotNull('counted_qty')]);
    }

    /**
     * @return array<string|int, mixed>
     */
    private function itemRelations(StockCount $count): array
    {
        return ['product.units', 'product.category', 'product.batches' => fn ($query) => $query->where('outlet_id', $count->outlet_id)->where('quantity', '>', 0)->fefo()];
    }

    /**
     * Opname terikat ke outletnya: mengubahnya hanya dari outlet aktif yang sama (header `X-Outlet-Id`),
     * supaya hitungan tidak masuk ke opname outlet lain setelah pengguna berganti outlet.
     */
    private function ensureActiveOutlet(StockCount $count): void
    {
        if (app(CurrentOutlet::class)->idOrPrimary() !== $count->outlet_id) {
            throw new PosException($this->otherOutletMessage($count), 'stock_count_other_outlet', ['outlet_id' => $count->outlet_id]);
        }
    }

    /**
     * @return array{client_uuid: ?string, status: string, reason: string, error: string, context: object}|null
     */
    private function otherOutletRejection(StockCount $count, ?string $clientUuid): ?array
    {
        if (app(CurrentOutlet::class)->idOrPrimary() === $count->outlet_id) {
            return null;
        }

        return ['client_uuid' => $clientUuid, 'status' => 'rejected', 'reason' => 'stock_count_other_outlet', 'error' => $this->otherOutletMessage($count), 'context' => (object) ['outlet_id' => $count->outlet_id]];
    }

    private function otherOutletMessage(StockCount $count): string
    {
        return "Opname {$count->number} milik outlet {$count->outlet?->name}. Pindah ke outlet itu dulu.";
    }

    private function ensureCan(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403, 'Anda tidak punya izin untuk tindakan ini.');
    }
}
