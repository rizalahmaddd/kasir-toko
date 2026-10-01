<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\Sales\ReceivablePaymentRequest;
use App\Http\Resources\V1\Sales\SaleDetailResource;
use App\Http\Resources\V1\Sales\SaleResource;
use App\Models\Sale;
use App\Services\Pos\SaleService;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[ApiTag('Piutang (Kasbon)', 'Kasir & Penjualan', 'Transaksi yang belum lunas dan pencatatan pelunasannya.')]
class ReceivableController extends Controller
{
    /**
     * Daftar kasbon.
     *
     * Terlama dulu. `meta.total_due` dan `meta.customer_count` dihitung dari semua kasbon, tanpa
     * filter pencarian.
     */
    #[ApiQuery('search', description: 'No. transaksi atau nama/HP pelanggan.')]
    #[ApiQuery('customer_id', 'integer', 'Kasbon satu pelanggan.')]
    #[ApiResponse(SaleResource::class, paginated: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $term = trim((string) $request->query('search'));

        $sales = Sale::query()
            ->completed()
            ->where('due_amount', '>', 0)
            ->when($request->integer('customer_id'), fn (Builder $query) => $query->where('customer_id', $request->integer('customer_id')))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('number', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $query) => $query->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))))
            ->with(['customer', 'cashier', 'payments'])
            ->withCount('items')
            ->orderBy('sold_at')
            ->paginate($this->perPage($request));

        return SaleResource::collection($sales)->additional(['meta' => [
            'total_due' => (int) Sale::query()->completed()->sum('due_amount'),
            'customer_count' => Sale::query()->completed()->where('due_amount', '>', 0)->distinct()->count('customer_id'),
        ]]);
    }

    /**
     * Catat pelunasan.
     *
     * Boleh sebagian. Pelunasan tunai wajib ada shift terbuka supaya masuk rekap laci.
     */
    public function pay(ReceivablePaymentRequest $request, Sale $sale, SaleService $sales): SaleDetailResource
    {
        $data = $request->validated();

        $sales->payReceivable($sale, $request->user(), (int) $data['amount'], PaymentMethod::from($data['method']), ($data['reference'] ?? null) ?: null);

        return new SaleDetailResource($sale->refresh());
    }
}
