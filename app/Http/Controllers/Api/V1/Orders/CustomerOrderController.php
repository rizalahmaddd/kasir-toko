<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Enums\CustomerOrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\Orders\CustomerOrderRequest;
use App\Http\Resources\V1\Orders\CustomerOrderResource;
use App\Models\CustomerOrder;
use App\Services\Pos\CustomerOrderService;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

#[ApiTag('Pesanan & Servis', 'Kasir & Penjualan', 'Pesanan dengan tanggal ambil & uang muka, dan tiket servis. Aktif bila kapabilitas `business.pre-order` menyala. Pelunasan lewat checkout dengan `customer_order_id`.')]
class CustomerOrderController extends Controller
{
    /**
     * Daftar pesanan & servis outlet aktif.
     */
    #[ApiQuery('search', description: 'Nomor, nama, telepon, atau IMEI.')]
    #[ApiQuery('status', description: '`open` (default), `all`, atau status pesanan.', enum: ['open', 'all', 'new', 'in_progress', 'ready', 'picked_up', 'cancelled'])]
    #[ApiQuery('type', description: '`order` atau `service`.')]
    #[ApiResponse(CustomerOrderResource::class, paginated: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('orders.manage');

        $status = (string) $request->query('status', 'open');
        $term = trim((string) $request->query('search'));

        $records = CustomerOrder::query()
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('number', 'like', "%{$term}%")->orWhere('customer_name', 'like', "%{$term}%")->orWhere('customer_phone', 'like', "%{$term}%")->orWhere('device_serial', 'like', "%{$term}%")))
            ->when(in_array($request->query('type'), [CustomerOrder::TYPE_ORDER, CustomerOrder::TYPE_SERVICE], true), fn (Builder $query) => $query->where('type', $request->query('type')))
            ->when($status === 'open', fn (Builder $query) => $query->open())
            ->when(CustomerOrderStatus::tryFrom($status), fn (Builder $query, CustomerOrderStatus $value) => $query->where('status', $value->value))
            ->orderByRaw('pickup_at is null')
            ->orderBy('pickup_at')
            ->latest('id')
            ->paginate($this->perPage($request));

        return CustomerOrderResource::collection($records);
    }

    public function show(CustomerOrder $order): CustomerOrderResource
    {
        Gate::authorize('orders.manage');

        return new CustomerOrderResource($order->load('items', 'payments'));
    }

    /**
     * Catat pesanan / tiket servis.
     *
     * `deposit` > 0 langsung dicatat sebagai uang muka dengan `deposit_method` (default tunai; tunai butuh shift terbuka).
     */
    #[ApiResponse(CustomerOrderResource::class, status: 201)]
    public function store(CustomerOrderRequest $request, CustomerOrderService $orders): CustomerOrderResource
    {
        return new CustomerOrderResource($orders->create($request->user(), $request->validated())->load('items', 'payments'));
    }

    /**
     * Terima uang muka tambahan.
     */
    public function pay(Request $request, CustomerOrder $order, CustomerOrderService $orders): CustomerOrderResource
    {
        Gate::authorize('orders.manage');
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:999999999'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $orders->pay($order, $request->user(), (int) $data['amount'], PaymentMethod::from($data['method']), $data['reference'] ?? null);

        return new CustomerOrderResource($order->refresh()->load('items', 'payments'));
    }

    /**
     * Ubah status (`new`, `in_progress`, `ready`). Selesai lewat pelunasan, batal lewat endpoint cancel.
     */
    public function status(Request $request, CustomerOrder $order, CustomerOrderService $orders): CustomerOrderResource
    {
        Gate::authorize('orders.manage');
        $data = $request->validate(['status' => ['required', Rule::in(['new', 'in_progress', 'ready'])]]);

        return new CustomerOrderResource($orders->setStatus($order, CustomerOrderStatus::from($data['status']))->load('items', 'payments'));
    }

    /**
     * Batalkan pesanan; `refund` true mengembalikan uang muka (tunai keluar dari laci shift akun ini).
     */
    public function cancel(Request $request, CustomerOrder $order, CustomerOrderService $orders): CustomerOrderResource
    {
        Gate::authorize('orders.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255'], 'refund' => ['boolean']]);

        return new CustomerOrderResource($orders->cancel($order, $request->user(), (bool) ($data['refund'] ?? true), $data['reason'])->load('items', 'payments'));
    }

    /**
     * Isi keranjang untuk pelunasan.
     *
     * Barang pesanan yang masih dijual (`items`: product_id, quantity, note), nama yang dilewati (`skipped`), dan
     * `deposit`. Kirim `customer_order_id` saat checkout; DP dipotong dari total oleh server.
     */
    public function cart(CustomerOrder $order, CustomerOrderService $orders): array
    {
        Gate::authorize('orders.manage');

        return ['data' => $orders->cartFor($order)];
    }
}
