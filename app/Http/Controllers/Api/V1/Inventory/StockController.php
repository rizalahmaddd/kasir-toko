<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Enums\StockMovementType;
use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\Inventory\StockAdjustmentRequest;
use App\Http\Resources\V1\Inventory\StockMovementResource;
use App\Http\Resources\V1\Inventory\StockSummaryResource;
use App\Http\Resources\V1\MasterData\ProductResource;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Pos\StockService;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
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
     * Tanpa `level` hanya produk aktif yang tampil.
     */
    #[ApiQuery('search', description: 'Cari nama, SKU, atau barcode.')]
    #[ApiQuery('level', description: '`low` stok menipis (masih ada), `out` habis/minus.', enum: ['low', 'out'])]
    #[ApiResponse(ProductResource::class, paginated: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('view-master-data');

        $level = $request->query('level');

        $records = Product::query()
            ->with('category')
            ->where('track_stock', true)
            ->search($request->query('search'))
            ->when($level === 'low', fn (Builder $query) => $query->lowStock()->where('stock', '>', 0))
            ->when($level === 'out', fn (Builder $query) => $query->where('stock', '<=', 0))
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

        return new StockSummaryResource([
            'tracked' => (clone $tracked)->count(),
            'low' => (clone $tracked)->lowStock()->where('stock', '>', 0)->count(),
            'out' => (clone $tracked)->where('stock', '<=', 0)->count(),
            'value' => (int) (clone $tracked)->where('stock', '>', 0)->sum(DB::raw('stock * cost_price')),
        ]);
    }

    /**
     * Kartu stok (riwayat mutasi).
     *
     * Terbaru dulu.
     */
    #[ApiQuery('product_id', 'integer', 'Mutasi satu produk saja.')]
    #[ApiQuery('type', description: 'Jenis mutasi.', enum: ['sale', 'sale_void', 'stock_in', 'stock_out', 'opname', 'initial'])]
    #[ApiQuery('search', description: 'Cari produk (diabaikan bila `product_id` diisi).')]
    #[ApiResponse(StockMovementResource::class, paginated: true)]
    public function movements(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('view-master-data');

        $productId = $request->integer('product_id') ?: null;
        $type = StockMovementType::tryFrom((string) $request->query('type'));

        $records = StockMovement::query()
            ->with(['product', 'user'])
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
     * dan sistem mencatat selisihnya.
     */
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
        );

        return new StockMovementResource($movement->load(['product', 'user']));
    }
}
