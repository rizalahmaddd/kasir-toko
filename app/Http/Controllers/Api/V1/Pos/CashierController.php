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
use App\Livewire\Pos\Cashier;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProductUnit;
use App\Models\Setting;
use App\Services\DocumentNumberGenerator;
use App\Services\Pos\HeldOrderService;
use App\Services\Pos\SaleService;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use App\Support\PosSettings;
use App\Support\Qris;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
            'block_expired_sale' => ['sometimes', 'boolean'],
            'expiry_warning_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'prescription_mode' => ['sometimes', Rule::in(array_keys(PosSettings::PRESCRIPTION_MODES))],
            'allow_controlled_drugs' => ['sometimes', 'boolean'],
            'service_charge_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'service_charge_dine_in_only' => ['sometimes', 'boolean'],
            'photo_retention_years' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'near_expiry_discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:90'],
            'near_expiry_discount_days' => ['sometimes', 'integer', 'min:0', 'max:60'],
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

        foreach (['near_expiry_discount_percent', 'near_expiry_discount_days'] as $key) {
            if (array_key_exists($key, $validated)) {
                $settings["pos.{$key}"] = (string) $validated[$key];
            }
        }

        if (array_key_exists('photo_retention_years', $validated)) {
            $settings['pharmacy.photo_retention_years'] = (string) $validated['photo_retention_years'];
        }

        if (array_key_exists('service_charge_rate', $validated)) {
            $settings['pos.service_charge_rate'] = (string) ($validated['service_charge_rate'] ?? '0');
        }

        foreach (['block_expired_sale', 'allow_controlled_drugs', 'service_charge_dine_in_only'] as $flag) {
            if (array_key_exists($flag, $validated)) {
                $settings["pos.{$flag}"] = $validated[$flag] ? '1' : '0';
            }
        }
        foreach (['expiry_warning_days', 'prescription_mode'] as $key) {
            if (array_key_exists($key, $validated)) {
                $settings["pos.{$key}"] = (string) $validated[$key];
            }
        }

        if (! empty($settings)) {
            Setting::putMany($settings);
        }

        return $this->config($request);
    }

    public function config(Request $request): PosConfigResource
    {
        $user = $request->user();
        $outlets = app(CurrentOutlet::class);
        $outlet = $outlets->get();
        $shift = $user->openShift();

        return new PosConfigResource([
            'outlet_id' => $outlet?->id,
            'outlet_name' => $outlet?->name,
            'is_multi_outlet' => $outlets->isMultiOutlet(),
            'outlet_locked' => $outlet !== null && ! $outlet->isOperational(),
            'shift_outlet_id' => $shift?->outlet_id,
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
            'has_open_shift' => $shift !== null,
            'block_expired_sale' => PosSettings::blockExpiredSale(),
            'expiry_warning_days' => PosSettings::expiryWarningDays(),
            'prescription_mode' => PosSettings::prescriptionMode(),
            'allow_controlled_drugs' => PosSettings::allowControlledDrugs(),
            'can_verify_prescription' => $user->can('pharmacy.prescription.verify'),
            'held_orders_count' => app(HeldOrderService::class)->query($user)->count(),
            'service_charge_rate' => PosSettings::serviceChargeConfiguredRate(),
            'service_charge_dine_in_only' => PosSettings::serviceChargeDineInOnly(),
            'order_type_enabled' => Features::enabledAt('business.order-type'),
            'modifiers_enabled' => Features::enabledAt('business.modifiers'),
            'tiered_price_enabled' => Features::enabledAt('business.tiered-price'),
            'variants_enabled' => Features::enabledAt('business.variants'),
            'near_expiry_discount_percent' => PosSettings::nearExpiryDiscountPercent(),
            'serials_enabled' => Features::enabledAt('business.serial-number'),
            'pre_order_enabled' => Features::enabledAt('business.pre-order') && $user->can('orders.manage'),
            'delivery_note_enabled' => Features::enabledAt('business.delivery-note'),
        ]);
    }

    /**
     * Kategori untuk tab kasir.
     *
     * Hanya kategori aktif yang punya produk aktif dan tersedia di outlet aktif.
     */
    #[ApiResponse(CategoryResource::class, collection: true)]
    public function categories(): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->availableAt(app(CurrentOutlet::class)->idOrPrimary() ?? 0)
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
     * Hanya produk aktif yang dijual di outlet aktif. Pakai `ids` untuk menyegarkan harga & stok keranjang yang dipulihkan;
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
            ->withOutletData()
            ->with(['category', 'units', 'priceTiers', 'modifierGroups.modifiers', 'components.component'])
            ->where('is_active', true)
            ->sellableAt()
            ->when($ids === null && Features::enabledAt('business.variants'), fn (Builder $query) => $query->whereNull('parent_id'))
            ->search($request->query('search'))
            ->when($request->integer('category_id'), fn (Builder $query, int $categoryId) => $query->where('category_id', $categoryId))
            ->when($ids !== null, fn (Builder $query) => $query->whereIn('products.id', $ids))
            ->orderBy('name')
            ->paginate($ids !== null ? 300 : $this->perPage($request, 48));

        Cashier::withVariants($records->getCollection());

        return ProductResource::collection($records);
    }

    /**
     * Cari produk dari barcode/SKU.
     *
     * Untuk scanner: barcode produk dicocokkan dulu, lalu barcode satuan jual (`matched_unit_id` menunjuk
     * satuan yang discan), lalu SKU, lalu nomor seri/IMEI yang tersedia (`matched_serial`). 404 bila tidak ada produk aktif;
     * pesannya menyebut nama produk bila produk ada tapi tidak dijual di outlet aktif.
     */
    #[ApiQuery('code', description: 'Hasil scan barcode atau SKU.', required: true)]
    public function lookup(Request $request): ProductResource
    {
        $code = trim($request->validate(['code' => ['required', 'string', 'max:64']])['code']);
        $query = fn () => Product::query()->withOutletData()->with(['category', 'units', 'priceTiers', 'modifierGroups.modifiers', 'components.component'])->where('is_active', true)->sellableAt();

        $product = $query()->where('barcode', $code)->first();

        if (! $product && ($unit = ProductUnit::query()->where('barcode', $code)->first())) {
            $product = $query()->whereKey($unit->product_id)->first()?->setAttribute('matched_unit_id', $unit->id);
        }

        $product ??= $query()->where('sku', $code)->first();

        if (! $product && Features::enabledAt('business.serial-number') && ($serial = ProductSerial::query()->available()->where('outlet_id', app(CurrentOutlet::class)->idOrPrimary())->where('serial', mb_strtoupper($code))->first())) {
            $product = $query()->whereKey($serial->product_id)->first()?->setAttribute('matched_serial', $serial->serial);
        }

        if ($product === null && ($elsewhere = Product::query()->where('is_active', true)->where(fn (Builder $query) => $query->where('barcode', $code)->orWhere('sku', $code))->first())) {
            abort(404, "{$elsewhere->name} tidak dijual di outlet ini.");
        }

        abort_if($product === null, 404);

        return new ProductResource(Cashier::withVariants(new EloquentCollection([$product]))->first());
    }

    /**
     * Nomor seri tersedia.
     *
     * Unit produk ini yang masih ada di stok outlet aktif (maks. 100), untuk dipilih saat menjual.
     */
    #[ApiQuery('search', description: 'Sebagian nomor seri.')]
    public function serials(Request $request, Product $product): array
    {
        return ['data' => app(Cashier::class)->availableSerials($product->id, (string) $request->query('search', ''))];
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
     * Transaksi masuk ke outlet shift yang sedang terbuka. Header `X-Outlet-Id` (dan `outlet_id` di
     * body, dikirim antrean offline) harus sama dengan outlet shift; selain itu 422 `outlet_mismatch`.
     * Outlet yang terkunci batas paket menolak checkout online dengan 423 `outlet_locked`; kirim
     * `offline: true` untuk transaksi dari antrean offline yang sudah terjadi, supaya tetap diterima.
     *
     * Rumus dengan service charge: service = `round((subtotal - diskon) x service_rate / 100)` (rate dari
     * `service_charge_rate`, 0 untuk pesanan selain makan di tempat bila `service_charge_dine_in_only`), pajak =
     * `round((subtotal - diskon + service) x tax_rate / 100)`, total = subtotal - diskon + service + pajak.
     * Baris dengan pilihan tambahan: `round((price + jumlah harga modifiers) x quantity) - discount`. Harga grosir:
     * `price` baris satuan dasar adalah harga tingkat tertinggi dari `price_tiers` yang tercapai oleh total
     * quantity produk itu di semua baris tanpa `unit_id` (tidak pernah di atas harga biasa).
     *
     * Tipe pesanan: kirim `order_type` (`dine_in|take_away|delivery`), `table_label` (makan di tempat), dan
     * `kitchen_sent` (peta tanda baris => jumlah yang sudah dikirim ke dapur, dari keranjang tertunda) supaya
     * tiket dapur hanya berisi tambahan baru. Pilihan tambahan: `items.*.modifiers` berisi `{id, name, price}`;
     * penolakan `modifier_unavailable` (context.modifier_ids), `modifier_required`, dan perubahan harga di
     * context.modifier_prices. Perubahan harga satuan dasar mengirim `base_price` & `tiers` di context.prices.
     *
     * Varian: induk (`variants` tidak kosong) ditolak 422 `variant_required`; jual SKU anaknya. Nomor seri: baris produk
     * `track_serial` wajib `items.*.serials` sebanyak quantity (`serial_required`, `serial_unavailable`; offline diterima &
     * ditandai `serial_unverified`). Pelunasan pesanan: kirim `customer_order_id`; DP pesanan dipotong dari total, jadi
     * `payments` cukup menutup sisanya (`order_closed`, `deposit_exceeds_total`).
     *
     * Diskon ED dekat: baris satuan dasar produk ber-batch kirim `auto_discount` = round(price × min(quantity, sisa
     * near_expiry_quantity) × near_expiry_discount_percent / 100), sisa dihitung berurutan per produk dari baris pertama.
     * Potongan ini ditambahkan ke `discount` baris dalam rumus; beda dengan server ditolak `near_expiry_changed`
     * (context.near_expiry per product_id: quantity & percent).
     *
     * Penolakan bisnis dikembalikan 422 dengan `reason`: `no_shift`, `forbidden_discount`,
     * `unavailable` (context.product_ids), `price_changed` (context.prices), `insufficient_stock`
     * (context.stock), `total_mismatch`, `underpaid`, `credit_needs_customer`, `overpaid_non_cash`, `outlet_mismatch`,
     * `unit_unavailable` (context.unit_ids), `expired_batch`, `controlled_drug`, `prescription_required`,
     * `prescription_unverified`, `prescription_exceeded`, `prescription_invalid`.
     *
     * Multi-satuan: baris boleh membawa `unit_id`; `price` adalah harga satuan itu dan `quantity` dalam satuan
     * itu (stok dipotong quantity × factor). Perubahan harga satuan dikirim di context.unit_prices (per unit_id).
     * Batch: `batch_id` opsional memilih batch; tanpa itu server memakai FEFO. Resep: kirim `prescription_id`
     * (resep tersimpan) atau `prescription` (dokter & pasien, dibuat saat checkout). Transaksi `offline` yang
     * melanggar aturan resep/kedaluwarsa tetap diterima dan ditandai di `flags`.
     */
    #[ApiResponse(SaleDetailResource::class, status: 201, description: 'Transaksi tersimpan (200 bila request ulang dengan client_uuid yang sama).')]
    public function checkout(CheckoutRequest $request, SaleService $sales): SaleDetailResource
    {
        return new SaleDetailResource($sales->checkout($request->user(), $request->validated()));
    }
}
