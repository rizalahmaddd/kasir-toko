<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Enums\StockMovementType;
use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\Inventory\StockAdjustmentRequest;
use App\Http\Resources\V1\Inventory\ProductBatchResource;
use App\Http\Resources\V1\Inventory\StockMovementResource;
use App\Http\Resources\V1\Inventory\StockSummaryResource;
use App\Http\Resources\V1\MasterData\ProductResource;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductStock;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Services\Pos\StockService;
use App\Support\CurrentOutlet;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use App\Support\PosSettings;
use App\Support\TenantRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

#[ApiTag('Stok', 'Stok', 'Posisi stok, kartu stok (mutasi), dan penyesuaian: stok masuk, stok keluar, dan stok opname. Hanya produk dengan `track_stock` true.')]
class StockController extends Controller
{
    /**
     * Posisi stok.
     *
     * Stok milik outlet yang sedang dipakai (header `X-Outlet-Id`). Tanpa `level` hanya produk aktif yang tampil.
     */
    #[ApiQuery('search', description: 'Cari nama, SKU, atau barcode.')]
    #[ApiQuery('level', description: '`low` stok menipis (masih ada), `out` habis/minus.', enum: ['low', 'out'])]
    #[ApiResponse(ProductResource::class, paginated: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('view-master-data');

        $level = $request->query('level');

        $records = Product::query()
            ->withOutletData()
            ->with(['category', 'units'])
            ->where('track_stock', true)
            ->search($request->query('search'))
            ->when($level === 'low', fn (Builder $query) => $query->lowStock()->whereOutletStock('>', 0))
            ->when($level === 'out', fn (Builder $query) => $query->outOfStock())
            ->when(! in_array($level, ['low', 'out'], true), fn (Builder $query) => $query->where('is_active', true))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return ProductResource::collection($records);
    }

    /**
     * Ringkasan stok.
     */
    public function summary(): StockSummaryResource
    {
        Gate::authorize('view-master-data');

        $tracked = Product::query()->where('track_stock', true)->where('is_active', true);
        $value = ProductStock::query()
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->where('product_stocks.outlet_id', app(CurrentOutlet::class)->idOrPrimary() ?? 0)
            ->where('product_stocks.stock', '>', 0)
            ->where('products.track_stock', true)
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->sum(DB::raw('product_stocks.stock * products.cost_price'));

        return new StockSummaryResource([
            'tracked' => (clone $tracked)->count(),
            'low' => (clone $tracked)->lowStock()->whereOutletStock('>', 0)->count(),
            'out' => (clone $tracked)->outOfStock()->count(),
            'value' => (int) $value,
        ]);
    }

    /**
     * Kartu stok (riwayat mutasi).
     *
     * Terbaru dulu.
     */
    #[ApiQuery('product_id', 'integer', 'Mutasi satu produk saja.')]
    #[ApiQuery('type', description: 'Jenis mutasi.', enum: ['sale', 'sale_void', 'stock_in', 'stock_out', 'opname', 'initial', 'transfer_out', 'transfer_in'])]
    #[ApiQuery('outlet_id', description: 'Id outlet atau `all`. Default outlet aktif; outlet lain dan `all` butuh izin `reports.all-outlets`.')]
    #[ApiQuery('search', description: 'Cari produk (diabaikan bila `product_id` diisi).')]
    #[ApiResponse(StockMovementResource::class, paginated: true)]
    public function movements(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('view-master-data');

        $productId = $request->integer('product_id') ?: null;
        $type = StockMovementType::tryFrom((string) $request->query('type'));

        $records = StockMovement::query()
            ->with(['product', 'user'])
            ->forOutlet($this->outletFilterId($request))
            ->when($productId, fn (Builder $query) => $query->where('product_id', $productId))
            ->when($type, fn (Builder $query) => $query->where('type', $type->value))
            ->when(! $productId && $request->filled('search'), fn (Builder $query) => $query->whereHas('product', fn (Builder $query) => $query->search($request->query('search'))))
            ->latest('id')
            ->paginate($this->perPage($request));

        return StockMovementResource::collection($records);
    }

    /**
     * Penyesuaian stok.
     *
     * `stock_in` menambah (dengan `unit_cost`, HPP dihitung ulang rata-rata tertimbang),
     * `stock_out` mengurangi (wajib `note`), `opname` mengisi `quantity` dengan hasil hitung fisik
     * dan sistem mencatat selisihnya. Semuanya berlaku di outlet yang sedang dipakai; outlet yang
     * terkunci batas paket ditolak dengan 423 `outlet_locked`.
     *
     * `unit_id` (stok masuk/keluar) mengisi `quantity` dan `unit_cost` dalam satuan itu; disimpan dalam satuan
     * dasar. Produk ber-batch: stok masuk wajib `batch_number` (422 `batch_required`), `expires_at` opsional;
     * stok keluar boleh memilih `batch_id`, tanpa itu diambil FEFO. Produk bernomor seri: stok masuk & keluar
     * wajib `serials` sebanyak jumlah unit (422 `serial_required`/`serial_taken`/`serial_unavailable`); opname ditolak.
     */
    /**
     * Opname per batch.
     *
     * `counts` hasil hitung fisik tiap batch (`{product_batch_id: jumlah}` dalam satuan dasar) dari daftar batch produk;
     * `extra` batch fisik yang belum tercatat. Stok outlet menjadi jumlah semua hitungan dan selisih per batch
     * tercatat di satu mutasi opname. 422 bila semua batch sama dengan stok sistem.
     */
    #[ApiResponse(StockMovementResource::class, status: 201)]
    public function batchOpname(Request $request, StockService $stock): StockMovementResource
    {
        Gate::authorize('inventory.manage');

        $data = $request->validate([
            'product_id' => ['required', 'integer', TenantRule::exists('products', 'id')],
            'counts' => ['required', 'array', 'min:1', 'max:200'],
            'counts.*' => ['numeric', 'min:0', 'max:99999999'],
            'extra' => ['nullable', 'array'],
            'extra.number' => ['nullable', 'string', 'max:50'],
            'extra.expires_at' => ['nullable', 'date'],
            'extra.quantity' => ['required_with:extra', 'numeric', 'min:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $extra = isset($data['extra']) ? ['number' => $data['extra']['number'] ?? null, 'expires_at' => $data['extra']['expires_at'] ?? null, 'quantity' => (float) $data['extra']['quantity']] : null;
        $movement = $stock->opnameBatches(Product::findOrFail($data['product_id']), $data['counts'], $request->user(), $data['note'] ?? null, null, $extra);

        return new StockMovementResource($movement->load(['product', 'user']));
    }

    #[ApiResponse(StockMovementResource::class, status: 201)]
    public function adjust(StockAdjustmentRequest $request, StockService $stock): StockMovementResource
    {
        $data = $request->validated();

        $movement = $stock->adjust(
            Product::findOrFail($data['product_id']),
            StockMovementType::from($data['type']),
            (float) $data['quantity'],
            $request->user(),
            ($data['note'] ?? null) ?: null,
            $data['type'] === 'stock_in' && isset($data['unit_cost']) ? (int) $data['unit_cost'] : null,
            null,
            isset($data['unit_id']) ? ProductUnit::query()->find($data['unit_id']) : null,
            [
                'number' => $data['batch_number'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'batch_id' => $data['batch_id'] ?? null,
                'serials' => $data['serials'] ?? [],
            ],
        );

        return new StockMovementResource($movement->load(['product', 'user']));
    }

    /**
     * Batch produk di outlet aktif.
     *
     * Batch bersaldo (bukan nol) urut FEFO: kedaluwarsa paling awal dulu, tanpa tanggal paling akhir.
     */
    #[ApiResponse(ProductBatchResource::class, collection: true)]
    public function batches(Product $product): AnonymousResourceCollection
    {
        Gate::authorize('view-master-data');

        return ProductBatchResource::collection(
            ProductBatch::query()->where('product_id', $product->id)->where('outlet_id', app(CurrentOutlet::class)->idOrPrimary())->where('quantity', '!=', 0)->fefo()->get()
        );
    }

    /**
     * Stok hampir/sudah kedaluwarsa.
     *
     * Batch bersaldo di outlet aktif yang kedaluwarsa sebelum `days` hari lagi (default pengaturan
     * peringatan toko), termasuk yang sudah lewat. Khusus paket Pro.
     */
    #[ApiQuery('days', 'integer', 'Batas hari ke depan, 0-365.')]
    #[ApiQuery('expired', 'boolean', 'Hanya yang sudah lewat tanggal.')]
    #[ApiResponse(ProductBatchResource::class, paginated: true)]
    public function expiring(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('view-master-data');

        $days = min(365, max(0, $request->integer('days', PosSettings::expiryWarningDays())));

        $records = ProductBatch::query()
            ->with('product')
            ->whereHas('product', fn (Builder $query) => $query->where('track_batch', true))
            ->where('outlet_id', app(CurrentOutlet::class)->idOrPrimary())
            ->where('quantity', '>', 0)
            ->whereNotNull('expires_at')
            ->when($request->boolean('expired'), fn (Builder $query) => $query->whereDate('expires_at', '<', today()), fn (Builder $query) => $query->whereDate('expires_at', '<=', today()->addDays($days)))
            ->orderBy('expires_at')
            ->paginate($this->perPage($request));

        return ProductBatchResource::collection($records);
    }
}
