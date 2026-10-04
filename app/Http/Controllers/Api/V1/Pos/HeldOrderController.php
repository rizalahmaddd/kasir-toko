<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\Pos\HoldOrderRequest;
use App\Http\Resources\V1\Pos\HeldOrderResource;
use App\Models\Customer;
use App\Models\HeldOrder;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

#[ApiTag('Transaksi Tertunda', 'Kasir & Penjualan', 'Keranjang yang disimpan sementara (maks. 30 per akun). Hanya milik akun sendiri yang terlihat.')]
class HeldOrderController extends Controller
{
    private const MAX_PER_USER = 30;

    /**
     * Daftar transaksi tertunda.
     */
    #[ApiResponse(HeldOrderResource::class, collection: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        return HeldOrderResource::collection(
            HeldOrder::query()->where('user_id', $request->user()->id)->latest()->get(),
        );
    }

    /**
     * Tunda transaksi.
     *
     * Label kosong diisi nama pelanggan atau "Pesanan #n".
     */
    #[ApiResponse(HeldOrderResource::class, status: 201)]
    public function store(HoldOrderRequest $request): HeldOrderResource
    {
        $userId = $request->user()->id;
        $cart = $request->input('cart');

        if (strlen((string) json_encode($cart)) > 200_000) {
            throw ValidationException::withMessages(['cart' => 'Keranjang terlalu besar untuk ditunda.']);
        }

        $count = HeldOrder::query()->where('user_id', $userId)->count();

        if ($count >= self::MAX_PER_USER) {
            throw ValidationException::withMessages(['message' => 'Transaksi tertunda sudah 30. Selesaikan atau hapus sebagian dulu.']);
        }

        $customerId = $cart['customer']['id'] ?? null;
        $label = trim((string) $request->input('label')) ?: (($cart['customer']['name'] ?? null) ?: 'Pesanan #'.($count + 1));

        $order = HeldOrder::create([
            'user_id' => $userId,
            'customer_id' => $customerId && Customer::query()->whereKey($customerId)->exists() ? (int) $customerId : null,
            'label' => mb_substr($label, 0, 60),
            'cart' => $cart,
            'item_count' => count($cart['items']),
            'total' => max(0, (int) ($cart['total'] ?? 0)),
        ]);

        return new HeldOrderResource($order);
    }

    /**
     * Lanjutkan transaksi tertunda.
     *
     * Mengembalikan `cart` lalu menghapusnya dari daftar. 404 bila sudah dilanjutkan di perangkat
     * lain. Segarkan harga lewat katalog kasir (`ids`) sebelum checkout.
     */
    public function resume(Request $request, int $heldOrder): HeldOrderResource
    {
        $order = HeldOrder::query()->where('user_id', $request->user()->id)->findOrFail($heldOrder);
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
        HeldOrder::query()->where('user_id', $request->user()->id)->whereKey($heldOrder)->delete();

        return response()->noContent();
    }
}
