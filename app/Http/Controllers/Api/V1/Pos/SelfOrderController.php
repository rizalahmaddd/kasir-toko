<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Resources\V1\Pos\SelfOrderResource;
use App\Http\Resources\V1\Sales\SaleDetailResource;
use App\Models\SelfOrder;
use App\Services\Pos\SelfOrderService;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[ApiTag('Pesanan QR', 'Kasir & Penjualan', 'Kotak masuk pesanan yang dikirim pelanggan dari QR meja atau standee di outlet aktif. Khusus paket Pro dengan kapabilitas `business.self-order`. Penolakan aturan dikembalikan sebagai 422 dengan `reason` (`self_order_state`, `insufficient_stock`). Realtime: event `self-order.changed` dan `self-order.ready` di channel privat outlet.')]
class SelfOrderController extends Controller
{
    public function __construct(private SelfOrderService $selfOrders) {}

    /**
     * Daftar pesanan QR yang masih terbuka.
     *
     * Status `pending`, `in_kitchen`, dan `ready` di outlet aktif, terlama dulu.
     */
    #[ApiResponse(SelfOrderResource::class, collection: true)]
    public function index(): AnonymousResourceCollection
    {
        return SelfOrderResource::collection($this->query()->open()->with('items')->oldest()->limit(100)->get());
    }

    /**
     * Terima pesanan ke open bill.
     *
     * Untuk `next_action` = `accept` (makan di tempat, bayar setelah makan): item masuk open bill meja,
     * tiket dapur terbit, dan `held_order_id` terisi. Stok dicek ulang; bila habis ditolak `insufficient_stock`.
     */
    public function accept(int $selfOrder): SelfOrderResource
    {
        return new SelfOrderResource($this->selfOrders->accept($this->find($selfOrder), request()->user())->load('items'));
    }

    /**
     * Keranjang untuk Proses Bayar.
     *
     * Untuk `next_action` = `pay`. Baris di `cart.items` membawa `self_order_item_id` dan harga yang dikunci
     * saat pelanggan memesan; kirim apa adanya ke checkout (`items.*.self_order_item_id`, `self_order_ids`).
     * Jumlah baris tidak boleh ditambah, dan harga grosir / potongan ED dekat tidak dihitung ulang untuk baris ini
     * (`auto_discount` dikirim sesuai `cart.items.*.auto_discount`).
     */
    public function cart(int $selfOrder): JsonResponse
    {
        return response()->json(['data' => $this->selfOrders->cartFor($this->find($selfOrder)->load('items'))]);
    }

    /**
     * Verifikasi pembayaran QRIS.
     *
     * Untuk `next_action` = `verify`, setelah kasir melihat uangnya di mutasi. Membuat transaksi lunas atas nama
     * kasir (butuh shift terbuka di outlet ini). Aman diulang: verifikasi kedua mengembalikan transaksi yang sama.
     */
    #[ApiResponse(SaleDetailResource::class, status: 201, description: 'Transaksi tersimpan (200 bila verifikasi diulang).')]
    public function verifyPayment(int $selfOrder): SaleDetailResource
    {
        return new SaleDetailResource($this->selfOrders->verifyPayment($this->find($selfOrder), request()->user()));
    }

    /**
     * Tolak pesanan atau pembayarannya.
     *
     * `target` = `payment`: uang QRIS tidak ditemukan; pesanan tetap terbuka dengan `next_action` = `decide`.
     * `target` = `order` (default): pesanan dibatalkan, tiket dapur yang belum dikerjakan ditarik, dan barisnya
     * dikeluarkan dari open bill. `reason` opsional ditampilkan ke pelanggan.
     */
    public function reject(Request $request, int $selfOrder): SelfOrderResource
    {
        $data = $request->validate([
            'target' => ['nullable', 'in:order,payment'],
            'reason' => ['nullable', 'string', 'max:150'],
        ]);

        $order = $this->find($selfOrder);
        $order = ($data['target'] ?? 'order') === 'payment'
            ? $this->selfOrders->rejectPayment($order)
            : $this->selfOrders->cancel($order, $data['reason'] ?? 'Ditolak kasir');

        return new SelfOrderResource($order->load('items'));
    }

    /**
     * Tagih di kasir setelah QRIS ditolak.
     *
     * Untuk `next_action` = `decide`. Metode berubah menjadi bayar di kasir; pesanan yang sudah di dapur
     * masuk open bill tanpa tiket dapur baru.
     */
    public function billAtCashier(int $selfOrder): SelfOrderResource
    {
        return new SelfOrderResource($this->selfOrders->billAtCashier($this->find($selfOrder), request()->user())->load('items'));
    }

    /**
     * Tandai sudah diantar / diambil.
     */
    public function served(int $selfOrder): SelfOrderResource
    {
        return new SelfOrderResource($this->selfOrders->markServed($this->find($selfOrder))->load('items'));
    }

    private function query()
    {
        abort_unless(Features::enabledAt('business.self-order'), 403, __('Fitur ini sedang dinonaktifkan.'));

        return SelfOrder::query()->forOutlet(app(CurrentOutlet::class)->idOrPrimary());
    }

    private function find(int $id): SelfOrder
    {
        return $this->query()->findOrFail($id);
    }
}
