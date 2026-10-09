<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\Sales\VoidSaleRequest;
use App\Http\Resources\V1\Sales\ReceiptResource;
use App\Http\Resources\V1\Sales\SaleDetailResource;
use App\Http\Resources\V1\Sales\SaleResource;
use App\Models\Sale;
use App\Services\Pos\SaleService;
use App\Support\CurrentOutlet;
use App\Support\DateInput;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use App\Support\PosSettings;
use App\Support\Receipt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[ApiTag('Riwayat Transaksi', 'Kasir & Penjualan', 'Tanpa izin `sales.view`, kasir hanya melihat transaksinya sendiri.')]
class SaleController extends Controller
{
    /**
     * Daftar transaksi.
     *
     * Default hari ini. `meta.summary` berisi jumlah & total transaksi selesai serta jumlah yang
     * dibatalkan untuk filter yang sama.
     */
    #[ApiQuery('from', 'date', 'Default hari ini.')]
    #[ApiQuery('to', 'date', 'Default hari ini.')]
    #[ApiQuery('status', description: '`credit` = masih ada kasbon.', enum: ['completed', 'voided', 'credit'])]
    #[ApiQuery('method', description: 'Metode pembayaran.', enum: ['cash', 'qris', 'transfer', 'card'])]
    #[ApiQuery('outlet_id', description: 'Id outlet atau `all`. Default outlet aktif; outlet lain dan `all` butuh izin `reports.all-outlets`.')]
    #[ApiQuery('cashier_id', 'integer', 'Hanya untuk akun dengan izin `sales.view`.')]
    #[ApiQuery('customer_id', 'integer', 'Transaksi satu pelanggan.')]
    #[ApiQuery('search', description: 'No. transaksi, nama/HP pelanggan, atau nama barang.')]
    #[ApiResponse(SaleResource::class, paginated: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $from = DateInput::valid((string) $request->query('from')) ?? today()->toDateString();
        $to = DateInput::valid((string) $request->query('to')) ?? today()->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $query = $this->filtered($request, $from, $to);

        $completed = (clone $query)->completed();
        $summary = [
            'count' => (clone $completed)->count(),
            'total' => (int) (clone $completed)->sum('total'),
            'voided' => (clone $query)->where('status', SaleStatus::Voided->value)->count(),
        ];

        $sales = $query
            ->with(['customer', 'cashier', 'payments', 'outlet'])
            ->withCount('items')
            ->latest('sold_at')
            ->latest('id')
            ->paginate($this->perPage($request));

        return SaleResource::collection($sales)->additional(['meta' => ['from' => $from, 'to' => $to, 'summary' => $summary]]);
    }

    /**
     * Detail transaksi.
     *
     * `abilities` menyebut aksi yang boleh dilakukan akun ini (batalkan, catat pelunasan).
     */
    public function show(Request $request, Sale $sale): SaleDetailResource
    {
        $this->authorizeView($request, $sale);

        return new SaleDetailResource($sale);
    }

    /**
     * Batalkan transaksi.
     *
     * Stok dikembalikan. Uang tunai dari shift yang sudah ditutup dicatat sebagai kas keluar di
     * shift pembatal yang sedang buka (422 bila belum buka shift).
     */
    public function void(VoidSaleRequest $request, Sale $sale, SaleService $sales): SaleDetailResource
    {
        $this->authorizeView($request, $sale);

        return new SaleDetailResource($sales->void($sale, $request->user(), $request->validated('reason')));
    }

    /**
     * Struk teks.
     *
     * Untuk dibagikan via WhatsApp atau dicetak ke printer thermal Bluetooth. Versi HTML ada di
     * `GET /api/v1/print/receipt/{sale}`.
     */
    public function receipt(Request $request, Sale $sale): ReceiptResource
    {
        $this->authorizeView($request, $sale);
        $sale->load('items', 'payments', 'cashier', 'customer');

        return new ReceiptResource([
            'number' => $sale->number,
            'text' => Receipt::text($sale),
            'whatsapp_url' => Receipt::whatsappUrl($sale),
            'paper_width' => app(CurrentOutlet::class)->run($sale->outlet_id, fn () => PosSettings::receiptWidth()),
        ]);
    }

    private function authorizeView(Request $request, Sale $sale): void
    {
        $user = $request->user();

        abort_unless($sale->user_id === $user->id || $user->can('sales.view'), 403);
    }

    /**
     * @return Builder<Sale>
     */
    private function filtered(Request $request, string $from, string $to): Builder
    {
        $user = $request->user();
        $canViewAll = $user->can('sales.view');
        $term = trim((string) $request->query('search'));
        $status = (string) $request->query('status');

        return Sale::query()
            ->forOutlet($this->outletFilterId($request))
            ->when(! $canViewAll, fn (Builder $query) => $query->where('user_id', $user->id))
            ->when($canViewAll && $request->integer('cashier_id'), fn (Builder $query) => $query->where('user_id', $request->integer('cashier_id')))
            ->when($request->integer('customer_id'), fn (Builder $query) => $query->where('customer_id', $request->integer('customer_id')))
            ->whereBetween('sold_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when(SaleStatus::tryFrom($status), fn (Builder $query, SaleStatus $status) => $query->where('status', $status->value))
            ->when($status === 'credit', fn (Builder $query) => $query->completed()->where('due_amount', '>', 0))
            ->when(PaymentMethod::tryFrom((string) $request->query('method')), fn (Builder $query, PaymentMethod $method) => $query->whereHas('payments', fn (Builder $query) => $query->where('method', $method->value)))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('number', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $query) => $query->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))
                ->orWhereHas('items', fn (Builder $query) => $query->where('product_name', 'like', "%{$term}%"))));
    }
}
