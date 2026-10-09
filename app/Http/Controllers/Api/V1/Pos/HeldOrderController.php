<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\Pos\HoldOrderRequest;
use App\Http\Resources\V1\Pos\HeldOrderResource;
use App\Services\Pos\HeldOrderService;
use App\Services\Pos\PosException;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

#[ApiTag('Transaksi Tertunda', 'Kasir & Penjualan', 'Keranjang yang disimpan sementara (maks. 30 per akun per outlet). Terlihat: milik akun sendiri di outlet yang sedang dipakai, plus open bill meja.')]
class HeldOrderController extends Controller
{
    public function __construct(private HeldOrderService $heldOrders) {}

    /**
     * Daftar transaksi tertunda.
     *
     * Milik akun ini di outlet aktif, ditambah open bill meja (`table_label` terisi) dari kasir lain bila
     * Tipe Pesanan & Meja aktif.
     */
    #[ApiResponse(HeldOrderResource::class, collection: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        return HeldOrderResource::collection($this->heldOrders->query($request->user())->latest()->get());
    }

    /**
     * Tunda transaksi.
     *
     * Label kosong diisi nama pelanggan atau "Pesanan #n". Dengan Tipe Pesanan & Meja, keranjang makan di tempat
     * yang punya `cart.table` menjadi open bill meja itu: menunda lagi untuk meja yang sama menggabungkan itemnya
     * (respons 200, `merged` true). Item yang belum pernah dikirim ke dapur dibuatkan tiket dapur (`kitchen_ticket_id`),
     * dan `cart.kitchenSent` diperbarui.
     */
    #[ApiResponse(HeldOrderResource::class, status: 201)]
    public function store(HoldOrderRequest $request): JsonResponse
    {
        try {
            $result = $this->heldOrders->hold($request->user(), (array) $request->input('cart'), (string) $request->input('label'));
        } catch (PosException $exception) {
            throw ValidationException::withMessages(['message' => $exception->getMessage()]);
        }

        $resource = new HeldOrderResource($result['order']);
        $resource->additional(['meta' => ['merged' => $result['merged'], 'kitchen_ticket_id' => $result['ticket']?->id]]);

        return $resource->response()->setStatusCode($result['merged'] ? 200 : 201);
    }

    /**
     * Lanjutkan transaksi tertunda.
     *
     * Mengembalikan `cart` lalu menghapusnya dari daftar. 404 bila sudah dilanjutkan di perangkat
     * lain. Segarkan harga lewat katalog kasir (`ids`) sebelum checkout.
     */
    public function resume(Request $request, int $heldOrder): HeldOrderResource
    {
        $order = $this->heldOrders->query($request->user())->findOrFail($heldOrder);
        $order->delete();

        $resource = new HeldOrderResource($order);
        $resource->withCart = true;

        return $resource;
    }

    /**
     * Hapus transaksi tertunda.
     */
    public function destroy(Request $request, int $heldOrder): Response
    {
        $this->heldOrders->query($request->user())->whereKey($heldOrder)->delete();

        return response()->noContent();
    }
}
