<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\Inventory\StockTransferRequest;
use App\Http\Resources\V1\Inventory\StockTransferResource;
use App\Models\StockTransfer;
use App\Services\Pos\StockTransferService;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[ApiTag('Transfer Stok', 'Stok', 'Memindahkan stok antar outlet. Berlaku seketika dan butuh izin `inventory.transfer`. Outlet asal dan tujuan harus bisa diakses akun ini dan tidak terkunci paket.')]
class StockTransferController extends Controller
{
    /**
     * Daftar transfer.
     *
     * Transfer yang melibatkan outlet yang boleh diakses akun ini, terbaru dulu.
     */
    #[ApiQuery('status', description: 'Filter status.', enum: ['completed', 'cancelled'])]
    #[ApiResponse(StockTransferResource::class, paginated: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('inventory.transfer'), 403);

        $transfers = StockTransfer::query()
            ->with(['fromOutlet', 'toOutlet', 'creator'])
            ->withCount('items')
            ->when(in_array($request->query('status'), [StockTransfer::STATUS_COMPLETED, StockTransfer::STATUS_CANCELLED], true), fn (Builder $query) => $query->where('status', $request->query('status')))
            ->latest('id')
            ->paginate($this->perPage($request));

        return StockTransferResource::collection($transfers);
    }

    /**
     * Detail transfer.
     */
    public function show(Request $request, StockTransfer $transfer): StockTransferResource
    {
        abort_unless($request->user()->can('inventory.transfer'), 403);

        return new StockTransferResource($transfer->load(['fromOutlet', 'toOutlet', 'creator', 'items.product']));
    }

    /**
     * Buat transfer.
     *
     * Stok langsung berkurang di outlet asal dan bertambah di outlet tujuan. 422 bila stok asal
     * kurang (kecuali stok minus diizinkan) atau produk tidak melacak stok.
     */
    #[ApiResponse(StockTransferResource::class, status: 201)]
    public function store(StockTransferRequest $request, StockTransferService $transfers)
    {
        $data = $request->validated();

        $transfer = $transfers->create($request->user(), (int) $data['from_outlet_id'], (int) $data['to_outlet_id'], $data['items'], ($data['note'] ?? null) ?: null);

        return $this->created(new StockTransferResource($transfer->load(['fromOutlet', 'toOutlet', 'creator', 'items.product'])));
    }

    /**
     * Batalkan transfer.
     *
     * Stok dikembalikan ke outlet asal. Ditolak 422 bila stok di outlet tujuan sudah terpakai dan
     * tidak cukup lagi.
     */
    public function cancel(Request $request, StockTransfer $transfer, StockTransferService $transfers): StockTransferResource
    {
        abort_unless($request->user()->can('inventory.transfer'), 403);

        return new StockTransferResource($transfers->cancel($transfer, $request->user())->load(['fromOutlet', 'toOutlet', 'creator', 'items.product']));
    }
}
