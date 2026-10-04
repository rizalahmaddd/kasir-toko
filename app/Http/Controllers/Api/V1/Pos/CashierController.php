<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\Pos\CheckoutRequest;
use App\Http\Requests\Api\V1\Pos\QuickCustomerRequest;
use App\Http\Resources\V1\MasterData\CategoryResource;
use App\Http\Resources\V1\MasterData\ProductResource;
use App\Http\Resources\V1\Pos\CustomerOptionResource;
use App\Http\Resources\V1\Pos\PosConfigResource;
use App\Http\Resources\V1\Pos\QrisResource;
use App\Http\Resources\V1\Sales\SaleDetailResource;
use App\Models\Category;
use App\Models\Customer;
use App\Models\HeldOrder;
use App\Models\Product;
use App\Models\Setting;
use App\Services\DocumentNumberGenerator;
use App\Services\Pos\SaleService;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use App\Support\PosSettings;
use App\Support\Qris;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

#[ApiTag('Kasir', 'Kasir & Penjualan', 'Alur layar kasir: ambil konfigurasi, katalog produk, pilih pelanggan, lalu checkout. Shift harus dibuka dulu (lihat Shift Saya).')]
class CashierController extends Controller
{
    /**
     * Konfigurasi kasir.
     *
     * Pajak, metode pembayaran aktif, nominal cepat, izin diskon akun ini, dan status shift.
     * Ambil ulang setelah membuka shift atau saat layar kasir dibuka.
     */
    /**
     * Ubah sebagian pengaturan kasir.
     */
    #[ApiResponse(PosConfigResource::class)]
    public function updateSettings(Request $request): PosConfigResource
    {
        Gate::authorize('settings.pos.manage');

        $validated = $request->validate([
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'allow_credit' => ['sometimes', 'boolean'],
            'auto_print' => ['sometimes', 'boolean'],
            'tax_enabled' => ['sometimes', 'boolean'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'tax_label' => ['sometimes', 'string', 'max:20'],
            'receipt_width' => ['sometimes', Rule::in(['58', '80'])],
            'receipt_header' => ['sometimes', 'nullable', 'string', 'max:300'],
            'receipt_footer' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        $settings = [];
        if (array_key_exists('allow_negative_stock', $validated)) {
            $settings['pos.allow_negative_stock'] = $validated['allow_negative_stock'] ? '1' : '0';
        }
        if (array_key_exists('allow_credit', $validated)) {
            $settings['pos.allow_credit'] = $validated['allow_credit'] ? '1' : '0';
        }
        if (array_key_exists('auto_print', $validated)) {
            $settings['pos.auto_print'] = $validated['auto_print'] ? '1' : '0';
        }
        if (array_key_exists('tax_enabled', $validated)) {
            $settings['pos.tax_enabled'] = $validated['tax_enabled'] ? '1' : '0';
        }
        if (array_key_exists('tax_rate', $validated)) {
            $settings['pos.tax_rate'] = (string) ($validated['tax_rate'] ?? '0');
        }
        if (array_key_exists('tax_label', $validated)) {
            $settings['pos.tax_label'] = $validated['tax_label'];
        }
        if (array_key_exists('receipt_width', $validated)) {
            $settings['pos.receipt_width'] = $validated['receipt_width'];
        }
        if (array_key_exists('receipt_header', $validated)) {
            $settings['pos.receipt_header'] = trim((string) $validated['receipt_header']);
        }
        if (array_key_exists('receipt_footer', $validated)) {
            $settings['pos.receipt_footer'] = trim((string) $validated['receipt_footer']);
        }

        if (! empty($settings)) {
            Setting::putMany($settings);
        }

        return $this->config($request);
    }

    public function config(Request $request): PosConfigResource
    {
        $user = $request->user();

        return new PosConfigResource([
            'tax_rate' => PosSettings::taxRate(),
            'tax_label' => PosSettings::taxLabel(),
            'allow_negative_stock' => PosSettings::allowNegativeStock(),
            'allow_credit' => PosSettings::allowCredit(),
            'can_discount' => $user->can('pos.discount'),
            'auto_print' => PosSettings::autoPrint(),
            'receipt_width' => PosSettings::receiptWidth(),
            'receipt_header' => PosSettings::get('pos.receipt_header'),
            'receipt_footer' => PosSettings::get('pos.receipt_footer'),
            'quick_cash' => PosSettings::quickCash(),
            'payment_methods' => array_map(fn (PaymentMethod $method) => [
                'value' => $method->value,
                'label' => $method->shortLabel(),
                'icon' => $method->icon(),
            ], PosSettings::paymentMethods()),
            'qris_enabled' => PosSettings::qrisPayload() !== null,
            'has_open_shift' => $user->openShift() !== null,
            'held_orders_count' => HeldOrder::query()->where('user_id', $user->id)->count(),
        ]);
    }

    /**
     * Kategori untuk tab kasir.
     *
     * Hanya kategori aktif yang punya produk aktif.
     */
    #[ApiResponse(CategoryResource::class, collection: true)]
    public function categories(): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->whereHas('products', fn (Builder $query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories);
    }

    /**
     * Katalog produk kasir.
     *
     * Hanya produk aktif. Pakai `ids` untuk menyegarkan harga & stok keranjang yang dipulihkan;
     * produk yang tidak kembali berarti sudah dihapus/nonaktif.
     */
    #[ApiQuery('search', description: 'Cari nama atau SKU, atau barcode yang persis sama.')]
    #[ApiQuery('category_id', 'integer', 'Filter kategori.')]
    #[ApiQuery('ids', description: 'Daftar ID dipisah koma, maks. 300.')]
    #[ApiResponse(ProductResource::class, paginated: true)]
    public function products(Request $request): AnonymousResourceCollection
    {
        $ids = $request->filled('ids')
            ? array_slice(array_filter(array_map('intval', explode(',', (string) $request->query('ids')))), 0, 300)
            : null;

        $records = Product::query()
            ->with('category')
            ->where('is_active', true)
            ->search($request->query('search'))
            ->when($request->integer('category_id'), fn (Builder $query, int $categoryId) => $query->where('category_id', $categoryId))
            ->when($ids !== null, fn (Builder $query) => $query->whereIn('id', $ids))
            ->orderBy('name')
            ->paginate($ids !== null ? 300 : $this->perPage($request, 48));

        return ProductResource::collection($records);
    }

    /**
     * Cari produk dari barcode/SKU.
     *
     * Untuk scanner: barcode dicocokkan dulu, lalu SKU. 404 bila tidak ada produk aktif.
     */
    #[ApiQuery('code', description: 'Hasil scan barcode atau SKU.', required: true)]
    public function lookup(Request $request): ProductResource
    {
        $code = trim($request->validate(['code' => ['required', 'string', 'max:64']])['code']);

        $product = Product::query()->with('category')->where('is_active', true)->where('barcode', $code)->first()
            ?? Product::query()->with('category')->where('is_active', true)->where('sku', $code)->firstOrFail();

        return new ProductResource($product);
    }

    /**
     * Cari pelanggan.
     *
     * Maks. 20 pelanggan aktif, beserta total kasbon yang belum lunas.
     */
    #[ApiQuery('search', description: 'Nama, nomor HP, atau kode.')]
    #[ApiResponse(CustomerOptionResource::class, collection: true)]
    public function customers(Request $request): AnonymousResourceCollection
    {
        $term = trim((string) $request->query('search'));

        $customers = Customer::query()
            ->where('is_active', true)
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")))
            ->withSum(['sales as due' => fn (Builder $query) => $query->completed()], 'due_amount')
            ->orderBy('name')
            ->limit(20)
            ->get();

        return CustomerOptionResource::collection($customers);
    }

    /**
     * Tambah pelanggan cepat.
     *
     * Cukup nama dan nomor HP; kode pelanggan dibuat otomatis.
     */
    #[ApiResponse(CustomerOptionResource::class, status: 201)]
    public function storeCustomer(QuickCustomerRequest $request, DocumentNumberGenerator $numbers): CustomerOptionResource
    {
        $customer = Customer::create([
            'code' => $numbers->next('PLG', 4),
            'name' => $request->validated('name'),
            'phone' => $request->validated('phone'),
            'payment_term_days' => 0,
            'is_active' => true,
        ]);

        return new CustomerOptionResource($customer);
    }

    /**
     * QRIS bernominal.
     *
     * Mengubah QRIS statis toko menjadi QRIS dinamis dengan nominal terkunci. 422 bila QRIS belum
     * diatur di Pengaturan Kasir.
     */
    #[ApiQuery('amount', 'integer', 'Nominal yang harus dibayar.', required: true)]
    public function qris(Request $request): QrisResource
    {
        $amount = (int) $request->validate(['amount' => ['required', 'integer', 'min:1', 'max:999999999999']])['amount'];
        $payload = PosSettings::qrisPayload();

        if ($payload === null) {
            abort(422, 'QRIS toko belum diatur di Pengaturan Kasir.');
        }

        $merchant = Qris::merchant($payload);

        return new QrisResource([
            'payload' => Qris::withAmount($payload, $amount),
            'amount' => $amount,
            'merchant_name' => $merchant['name'],
            'merchant_city' => $merchant['city'],
        ]);
    }

    /**
     * Checkout (bayar).
     *
     * Harga, stok, dan total dihitung ulang di server. Rumus: tiap baris `round(price x quantity) -
     * discount`; subtotal = jumlah baris; diskon transaksi `percent` (0-100) atau `amount`; pajak
     * `round((subtotal - diskon) x tax_rate / 100)`; total = subtotal - diskon + pajak.
     *
     * Kirim `client_uuid` (UUID acak per transaksi). Request ulang dengan UUID yang sama (mis. setelah
     * koneksi putus) mengembalikan transaksi yang sudah tersimpan dengan status 200, bukan transaksi
     * baru (201). Pembayaran kurang dicatat sebagai kasbon bila diizinkan dan pelanggan dipilih.
     *
     * Penolakan bisnis dikembalikan 422 dengan `reason`: `no_shift`, `forbidden_discount`,
     * `unavailable` (context.product_ids), `price_changed` (context.prices), `insufficient_stock`
     * (context.stock), `total_mismatch`, `underpaid`, `credit_needs_customer`, `overpaid_non_cash`.
     */
    #[ApiResponse(SaleDetailResource::class, status: 201, description: 'Transaksi tersimpan (200 bila request ulang dengan client_uuid yang sama).')]
    public function checkout(CheckoutRequest $request, SaleService $sales): SaleDetailResource
    {
        return new SaleDetailResource($sales->checkout($request->user(), $request->validated()));
    }
}
