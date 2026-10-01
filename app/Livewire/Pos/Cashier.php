<?php

namespace App\Livewire\Pos;

use App\Enums\CashMovementType;
use App\Models\CashShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\HeldOrder;
use App\Models\Product;
use App\Services\DocumentNumberGenerator;
use App\Services\Pos\PosException;
use App\Services\Pos\ShiftService;
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
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()
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
        return Product::query()
            ->where('is_active', true)
            ->search($this->search)
            ->when($this->categoryId, fn (Builder $query) => $query->where('category_id', $this->categoryId))
            ->orderBy('name')
            ->limit($this->limit + 1)
            ->get();
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

        $product = Product::query()->where('is_active', true)->where('barcode', $code)->first()
            ?? Product::query()->where('is_active', true)->where('sku', $code)->first();

        return $product ? self::productPayload($product) : null;
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
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get()
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
     */
    #[Renderless]
    public function holdOrder(array $cart, string $label = ''): array
    {
        $items = array_values(array_filter($cart['items'] ?? [], 'is_array'));

        if ($items === []) {
            return ['ok' => false, 'message' => 'Keranjang masih kosong.'];
        }

        if (count($items) > 300 || strlen((string) json_encode($cart)) > 200_000) {
            return ['ok' => false, 'message' => 'Keranjang terlalu besar untuk ditunda.'];
        }

        $count = HeldOrder::query()->where('user_id', auth()->id())->count();

        if ($count >= 30) {
            return ['ok' => false, 'message' => 'Transaksi tertunda sudah 30. Selesaikan atau hapus sebagian dulu.'];
        }

        $label = trim($label) ?: (($cart['customer']['name'] ?? null) ?: 'Pesanan #'.($count + 1));

        HeldOrder::create([
            'user_id' => auth()->id(),
            'customer_id' => isset($cart['customer']['id']) && Customer::query()->whereKey($cart['customer']['id'])->exists() ? (int) $cart['customer']['id'] : null,
            'label' => mb_substr($label, 0, 60),
            'cart' => $cart,
            'item_count' => count($items),
            'total' => max(0, (int) ($cart['total'] ?? 0)),
        ]);

        return ['ok' => true, 'count' => $count + 1];
    }

    /**
     * @return list<array{id: int, label: string, item_count: int, total: int, created: string}>
     */
    #[Renderless]
    public function heldOrders(): array
    {
        return HeldOrder::query()
            ->where('user_id', auth()->id())
            ->latest()
            ->get()
            ->map(fn (HeldOrder $order) => [
                'id' => $order->id,
                'label' => $order->label,
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
        $order = HeldOrder::query()->where('user_id', auth()->id())->find($id);

        if (! $order) {
            return null;
        }

        $cart = $order->cart;
        $order->delete();

        return $cart;
    }

    #[Renderless]
    public function deleteHeldOrder(int $id): void
    {
        HeldOrder::query()->where('user_id', auth()->id())->whereKey($id)->delete();
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
        return CustomerDisplaySettings::enabled() && Features::enabled('pos.customer-display');
    }

    /**
     * @return array<string, mixed>
     */
    public static function productPayload(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'unit' => $product->unit,
            'price' => $product->price,
            'track' => $product->track_stock,
            'stock' => $product->track_stock ? (float) $product->stock : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $user = auth()->user();

        return [
            'userId' => $user->id,
            'taxRate' => PosSettings::taxRate(),
            'taxLabel' => PosSettings::taxLabel(),
            'allowNegative' => PosSettings::allowNegativeStock(),
            'allowCredit' => PosSettings::allowCredit(),
            'canDiscount' => $user->can('pos.discount'),
            'autoPrint' => PosSettings::autoPrint(),
            'quickCash' => PosSettings::quickCash(),
            'methods' => collect(PosSettings::paymentMethods())->map(fn ($method) => ['value' => $method->value, 'label' => $method->shortLabel(), 'icon' => $method->icon()])->all(),
            'checkoutUrl' => route('pos.checkout'),
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
            'heldCount' => HeldOrder::query()->where('user_id', auth()->id())->count(),
        ]);
    }
}
