<?php

namespace App\Livewire\Pos;

use App\Enums\CashMovementType;
use App\Enums\OrderType;
use App\Models\CashShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\HeldOrder;
use App\Models\Outlet;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProductUnit;
use App\Services\DocumentNumberGenerator;
use App\Services\Pos\BatchService;
use App\Services\Pos\CustomerOrderService;
use App\Services\Pos\HeldOrderService;
use App\Services\Pos\ModifierService;
use App\Services\Pos\PosException;
use App\Services\Pos\ShiftService;
use App\Support\CurrentOutlet;
use App\Support\CustomerDisplaySettings;
use App\Support\Features;
use App\Support\PosSettings;
use App\Support\QrCodeSvg;
use App\Support\Qris;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Layar kasir. Katalog produk dirender server, keranjang dipegang Alpine (resources/js/pos.js)
 * supaya tombol +/- terasa instan di tablet dan keranjang tetap ada walau halaman termuat ulang.
 * Checkout lewat PosCheckoutController, yang menghitung ulang semuanya dari database.
 */
#[Layout('layouts.pos')]
#[Title('Kasir')]
class Cashier extends Component
{
    private const PAGE_SIZE = 48;

    private const PAYLOAD_RELATIONS = ['units', 'priceTiers', 'modifierGroups.modifiers'];

    public string $search = '';

    public ?int $categoryId = null;

    public int $limit = self::PAGE_SIZE;

    public string $openingCash = '';

    public string $cashType = 'out';

    public string $cashAmount = '';

    public string $cashReason = '';

    public function updatedSearch(): void
    {
        $this->limit = self::PAGE_SIZE;
    }

    public function selectCategory(?int $categoryId): void
    {
        $this->categoryId = $categoryId;
        $this->limit = self::PAGE_SIZE;
    }

    public function loadMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    #[Computed]
    public function shift(): ?CashShift
    {
        return auth()->user()->openShift();
    }

    /**
     * Shift terbuka di outlet lain daripada yang sedang dipilih: kasir harus pindah outlet atau menutupnya dulu.
     */
    #[Computed]
    public function shiftInOtherOutlet(): ?Outlet
    {
        $shift = $this->shift;

        return $shift && $shift->outlet_id !== app(CurrentOutlet::class)->id() ? $shift->outlet : null;
    }

    #[Computed]
    public function outlet(): ?Outlet
    {
        return app(CurrentOutlet::class)->get();
    }

    #[Computed]
    public function outletLocked(): bool
    {
        $outlet = $this->outlet;

        return $outlet !== null && ! $outlet->isOperational();
    }

    private function heldOrdersQuery(): Builder
    {
        return app(HeldOrderService::class)->query(auth()->user());
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()
            ->availableAt(app(CurrentOutlet::class)->idOrPrimary() ?? 0)
            ->where('is_active', true)
            ->whereHas('products', fn (Builder $query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function products(): Collection
    {
        return self::withVariants(Product::query()
            ->withOutletData()
            ->with(self::PAYLOAD_RELATIONS)
            ->where('is_active', true)
            ->sellableAt()
            ->when(Features::enabledAt('business.variants'), fn (Builder $query) => $query->whereNull('parent_id'))
            ->search($this->search)
            ->when($this->categoryId, fn (Builder $query) => $query->where('category_id', $this->categoryId))
            ->orderBy('name')
            ->limit($this->limit + 1)
            ->get());
    }

    /**
     * SKU anak varian aktif (dengan harga & stok outlet) dimuat sekaligus untuk produk induk di daftar.
     *
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    public static function withVariants(Collection $products): Collection
    {
        self::withNearExpiry($products);
        $parents = $products->filter(fn (Product $product) => $product->isVariantParent());

        if ($parents->isEmpty() || ! Features::enabledAt('business.variants')) {
            return $products;
        }

        $children = Product::query()->withOutletData()->with(self::PAYLOAD_RELATIONS)->where('is_active', true)
            ->whereIn('parent_id', $parents->pluck('id'))->orderBy('name')->get()->groupBy('parent_id');

        $parents->each(fn (Product $parent) => $parent->setRelation('variants', self::withNearExpiry($children->get($parent->id, new Collection))));

        return $products;
    }

    /**
     * Jumlah unit dari batch hampir kedaluwarsa di outlet aktif (attribute near_expiry_quantity), untuk potongan ED dekat.
     *
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    public static function withNearExpiry(Collection $products): Collection
    {
        $quantities = $products->isEmpty() ? [] : BatchService::nearExpiryQuantities($products->pluck('id'), app(CurrentOutlet::class)->idOrPrimary());

        $products->each(fn (Product $product) => $product->setAttribute('near_expiry_quantity', $quantities[$product->id] ?? 0.0));

        return $products;
    }

    public function openShift(ShiftService $shifts): void
    {
        $this->validate(
            ['openingCash' => ['required', 'integer', 'min:0', 'max:999999999']],
            ['openingCash.required' => 'Isi modal awal laci, boleh 0.'],
        );

        try {
            $shift = $shifts->open(auth()->user(), (int) $this->openingCash);
        } catch (PosException $exception) {
            $this->addError('openingCash', $exception->getMessage());

            return;
        }

        $this->reset('openingCash');
        unset($this->shift);
        $this->dispatch('close-modal', 'open-shift');
        $this->dispatch('pos-shift-opened');
        $this->dispatch('notify', message: "Shift {$shift->number} dibuka. Selamat bekerja!");
    }

    public function recordCash(ShiftService $shifts): void
    {
        $this->validate([
            'cashType' => ['required', Rule::enum(CashMovementType::class)],
            'cashAmount' => ['required', 'integer', 'min:1', 'max:999999999'],
            'cashReason' => ['required', 'string', 'max:150'],
        ], [
            'cashAmount.required' => 'Isi nominalnya.',
            'cashReason.required' => 'Tulis keperluannya, mis. beli es batu atau setor ke pemilik.',
        ]);

        $shift = $this->shift;

        if (! $shift) {
            $this->addError('cashAmount', 'Buka shift dulu.');

            return;
        }

        try {
            $movement = $shifts->recordCash($shift, CashMovementType::from($this->cashType), (int) $this->cashAmount, $this->cashReason, auth()->user());
        } catch (PosException $exception) {
            $this->addError('cashAmount', $exception->getMessage());

            return;
        }

        $this->reset('cashAmount', 'cashReason');
        $this->dispatch('close-modal', 'cash-movement');
        $this->dispatch('notify', message: $movement->type->label().' tercatat.');
    }

    /**
     * Pencarian persis untuk scanner barcode: barcode dulu, lalu SKU.
     *
     * @return array<string, mixed>|null
     */
    #[Renderless]
    public function findByCode(string $code): ?array
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        $query = fn () => Product::query()->withOutletData()->with(self::PAYLOAD_RELATIONS)->where('is_active', true)->sellableAt();
        $product = $query()->where('barcode', $code)->first();

        if (! $product && Features::enabledAt('business.multi-unit') && ($unit = ProductUnit::query()->where('barcode', $code)->first())) {
            $product = $query()->whereKey($unit->product_id)->first();

            return $product ? [...self::productPayload(self::withNearExpiry(new Collection([$product]))->first()), 'unit_id' => $unit->id] : null;
        }

        $product ??= $query()->where('sku', $code)->first();

        if (! $product && Features::enabledAt('business.serial-number') && ($serial = ProductSerial::query()->available()->where('outlet_id', app(CurrentOutlet::class)->idOrPrimary())->where('serial', mb_strtoupper($code))->first())) {
            $product = $query()->whereKey($serial->product_id)->first();

            return $product ? [...self::productPayload(self::withNearExpiry(new Collection([$product]))->first()), 'serial_code' => $serial->serial] : null;
        }

        if (! $product && ($elsewhere = Product::query()->where('is_active', true)->where(fn (Builder $query) => $query->where('barcode', $code)->orWhere('sku', $code))->first())) {
            return ['blocked' => "{$elsewhere->name} tidak dijual di outlet ini."];
        }

        return $product ? self::productPayload(self::withVariants(new Collection([$product]))->first()) : null;
    }

    /**
     * Data terbaru produk di keranjang, untuk keranjang lama yang dipulihkan dari perangkat.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    #[Renderless]
    public function syncProducts(array $ids): array
    {
        $ids = array_slice(array_filter(array_map('intval', $ids)), 0, 300);

        return Product::query()
            ->withOutletData()
            ->with(self::PAYLOAD_RELATIONS)
            ->whereIn('products.id', $ids)
            ->where('is_active', true)
            ->sellableAt()
            ->get()
            ->pipe(fn (Collection $products) => self::withNearExpiry($products))
            ->mapWithKeys(fn (Product $product) => [$product->id => self::productPayload($product)])
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, phone: ?string, code: string, due: int}>
     */
    #[Renderless]
    public function searchCustomers(string $term = ''): array
    {
        $term = trim($term);

        return Customer::query()
            ->where('is_active', true)
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")))
            ->withSum(['sales as due' => fn (Builder $query) => $query->completed()], 'due_amount')
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'code' => $customer->code,
                'due' => (int) $customer->due,
                'credit_limit' => $customer->credit_limit,
            ])
            ->all();
    }

    /**
     * @return array{ok: bool, message?: string, customer?: array{id: int, name: string, phone: ?string, code: string, due: int}}
     */
    #[Renderless]
    public function quickAddCustomer(string $name, string $phone, DocumentNumberGenerator $numbers): array
    {
        $name = trim($name);
        $phone = preg_replace('/[^0-9+]/', '', $phone) ?? '';

        if ($name === '' || mb_strlen($name) > 150) {
            return ['ok' => false, 'message' => 'Nama pelanggan wajib diisi (maks. 150 karakter).'];
        }

        if ($phone !== '' && Customer::query()->where('phone', $phone)->exists()) {
            return ['ok' => false, 'message' => 'Nomor HP ini sudah terdaftar. Cari pelanggannya di daftar.'];
        }

        $customer = Customer::create([
            'code' => $numbers->next('PLG', 4),
            'name' => $name,
            'phone' => $phone ?: null,
            'payment_term_days' => 0,
            'is_active' => true,
        ]);

        return ['ok' => true, 'customer' => ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone, 'code' => $customer->code, 'due' => 0]];
    }

    /**
     * @param  array<string, mixed>  $cart
     * @return array{ok: bool, message?: string, merged?: bool, label?: string, ticket_url?: ?string}
     */
    #[Renderless]
    public function holdOrder(array $cart, string $label = ''): array
    {
        try {
            $result = app(HeldOrderService::class)->hold(auth()->user(), $cart, $label);
        } catch (PosException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }

        return [
            'ok' => true,
            'merged' => $result['merged'],
            'label' => $result['order']->label,
            'ticket_url' => $result['ticket'] ? route('print.kitchen-ticket', $result['ticket']) : null,
        ];
    }

    /**
     * @return list<array{id: int, label: string, item_count: int, total: int, created: string}>
     */
    #[Renderless]
    public function heldOrders(): array
    {
        return $this->heldOrdersQuery()
            ->latest()
            ->get()
            ->map(fn (HeldOrder $order) => [
                'id' => $order->id,
                'label' => $order->label,
                'table' => $order->table_label,
                'shared' => $order->user_id !== auth()->id(),
                'item_count' => $order->item_count,
                'total' => $order->total,
                'created' => $order->created_at->diffForHumans(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Renderless]
    public function resumeHeldOrder(int $id): ?array
    {
        return app(HeldOrderService::class)->resume(auth()->user(), $id);
    }

    #[Renderless]
    public function deleteHeldOrder(int $id): void
    {
        $this->heldOrdersQuery()->whereKey($id)->delete();
    }

    /**
     * @return array{svg: string, merchant: string}|null
     */
    #[Renderless]
    public function qrisSvg(int $amount): ?array
    {
        $payload = PosSettings::qrisPayload();

        if ($payload === null || $amount <= 0 || $amount > 999_999_999_999) {
            return null;
        }

        return ['svg' => Qris::svg(Qris::withAmount($payload, $amount)), 'merchant' => Qris::merchant($payload)['name']];
    }

    /**
     * @return array{url: string, qr: string}|null
     */
    #[Renderless]
    public function displayLink(bool $rotate = false): ?array
    {
        if (! $this->displayAvailable()) {
            return null;
        }

        $user = auth()->user();
        $key = $rotate ? $user->rotateDisplayKey() : $user->displayKey();
        $url = route('display.show', $key);

        return ['url' => $url, 'qr' => QrCodeSvg::render($url)];
    }

    private function displayAvailable(): bool
    {
        return CustomerDisplaySettings::enabled() && Features::enabledAt('pos.customer-display');
    }

    /**
     * @return array<string, mixed>
     */
    public static function productPayload(Product $product): array
    {
        $price = $product->effectivePrice();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'unit' => $product->unit,
            'price' => $price,
            'track' => $product->track_stock,
            'stock' => $product->track_stock ? $product->outletStock() : null,
            'units' => Features::enabledAt('business.multi-unit') ? $product->units->map(fn (ProductUnit $unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
                'factor' => (float) $unit->factor,
                'price' => $unit->priceFrom($price),
                'default' => $unit->is_default_sale,
            ])->values()->all() : [],
            'rx' => $product->requires_prescription && Features::enabledAt('business.prescription'),
            'tiers' => Features::enabledAt('business.tiered-price') ? $product->priceTiers->map(fn ($tier) => ['min' => (float) $tier->min_quantity, 'price' => (int) $tier->price])->values()->all() : [],
            'modifier_groups' => Features::enabledAt('business.modifiers') ? ModifierService::payload($product) : [],
            'near_expiry' => (float) ($product->getAttribute('near_expiry_quantity') ?? 0) > 0 ? (float) $product->getAttribute('near_expiry_quantity') : 0,
            'serial' => $product->tracksSerials(),
            'variant' => $product->variantLabel(),
            'variants' => Features::enabledAt('business.variants') && $product->isVariantParent() && $product->relationLoaded('variants')
                ? $product->variants->map(fn (Product $child) => self::productPayload($child))->values()->all()
                : [],
        ];
    }

    /**
     * Nomor seri yang masih ada di stok outlet ini untuk dipilih kasir.
     *
     * @return list<string>
     */
    #[Renderless]
    public function availableSerials(int $productId, string $term = ''): array
    {
        if (! Features::enabledAt('business.serial-number')) {
            return [];
        }

        return ProductSerial::query()->available()
            ->where('product_id', $productId)
            ->where('outlet_id', app(CurrentOutlet::class)->idOrPrimary())
            ->when(trim($term) !== '', fn (Builder $query) => $query->where('serial', 'like', '%'.mb_strtoupper(trim($term)).'%'))
            ->orderBy('id')
            ->limit(100)
            ->pluck('serial')
            ->all();
    }

    /**
     * Isi keranjang untuk melunasi pesanan: barang pesanan dengan data katalog terbaru dan uang mukanya.
     *
     * @return array<string, mixed>|null
     */
    #[Renderless]
    public function orderCart(int $orderId, CustomerOrderService $orders): ?array
    {
        if (! Features::enabledAt('business.pre-order') || ! auth()->user()->can('orders.manage')) {
            return null;
        }

        $order = CustomerOrder::query()->find($orderId);

        if (! $order || ! $order->isOpen()) {
            return null;
        }

        $cart = $orders->cartFor($order);
        $products = self::withNearExpiry(Product::query()->withOutletData()->with(self::PAYLOAD_RELATIONS)->whereIn('products.id', collect($cart['items'])->pluck('product_id'))->get())->keyBy('id');

        return [
            ...$cart,
            'items' => collect($cart['items'])->map(fn (array $item) => ['product' => self::productPayload($products->get($item['product_id'])), 'quantity' => $item['quantity'], 'note' => $item['note']])->all(),
        ];
    }

    /**
     * Resep yang masih bisa ditebus, untuk ditautkan ke keranjang.
     *
     * @return list<array<string, mixed>>
     */
    #[Renderless]
    public function searchPrescriptions(string $term = ''): array
    {
        if (! Features::enabledAt('business.prescription') || ! auth()->user()->can('pharmacy.prescription.view')) {
            return [];
        }

        return Prescription::query()
            ->open()
            ->with('items')
            ->search($term)
            ->latest('prescription_date')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (Prescription $prescription) => [
                'id' => $prescription->id,
                'number' => $prescription->number,
                'patient' => $prescription->patient_name,
                'doctor' => $prescription->doctor_name,
                'date' => $prescription->prescription_date->translatedFormat('d M Y'),
                'verified' => $prescription->isVerified(),
                'items' => $prescription->items->map(fn ($item) => ['product_id' => $item->product_id, 'name' => $item->product_name, 'remaining' => $item->remaining()])->values()->all(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $user = auth()->user();

        return [
            'userId' => $user->id,
            'outletId' => app(CurrentOutlet::class)->idOrPrimary(),
            'taxRate' => PosSettings::taxRate(),
            'taxLabel' => PosSettings::taxLabel(),
            'allowNegative' => PosSettings::allowNegativeStock(),
            'allowCredit' => PosSettings::allowCredit(),
            'canDiscount' => $user->can('pos.discount'),
            'autoPrint' => PosSettings::autoPrint(),
            'quickCash' => PosSettings::quickCash(),
            'methods' => collect(PosSettings::paymentMethods())->map(fn ($method) => ['value' => $method->value, 'label' => $method->shortLabel(), 'icon' => $method->icon()])->all(),
            'checkoutUrl' => route('pos.checkout'),
            'multiUnit' => Features::enabledAt('business.multi-unit'),
            'variants' => Features::enabledAt('business.variants'),
            'serials' => Features::enabledAt('business.serial-number'),
            'orderToLoad' => Features::enabledAt('business.pre-order') ? request()->integer('order') ?: null : null,
            'modifiers' => Features::enabledAt('business.modifiers'),
            'tieredPrice' => Features::enabledAt('business.tiered-price'),
            'nearExpiryPercent' => PosSettings::nearExpiryDiscountPercent(),
            'orderType' => [
                'enabled' => Features::enabledAt('business.order-type'),
                'options' => collect(OrderType::cases())->map(fn (OrderType $type) => ['value' => $type->value, 'label' => $type->shortLabel()])->all(),
            ],
            'serviceCharge' => [
                'rate' => PosSettings::serviceChargeConfiguredRate(),
                'dineInOnly' => PosSettings::serviceChargeDineInOnly(),
            ],
            'prescription' => [
                'enabled' => Features::enabledAt('business.prescription'),
                'mode' => PosSettings::prescriptionMode(),
                'canVerify' => $user->can('pharmacy.prescription.verify'),
                'canView' => $user->can('pharmacy.prescription.view'),
                'createUrl' => $user->can('pharmacy.prescription.manage') ? route('pharmacy.prescriptions', ['create' => 1]) : null,
            ],
            'qris' => PosSettings::qrisPayload() !== null,
            'display' => [
                'enabled' => $this->displayAvailable(),
                'linked' => (bool) $user->display_key,
                'pushUrl' => route('pos.display.push'),
            ],
        ];
    }

    public function render()
    {
        return view('livewire.pos.cashier', [
            'heldCount' => $this->heldOrdersQuery()->count(),
        ]);
    }
}
