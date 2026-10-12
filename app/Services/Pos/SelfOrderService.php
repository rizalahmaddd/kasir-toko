<?php

namespace App\Services\Pos;

use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Events\SelfOrderChanged;
use App\Events\SelfOrderReady;
use App\Events\SelfOrderStatusUpdated;
use App\Models\CashShift;
use App\Models\DiningTable;
use App\Models\HeldOrder;
use App\Models\KitchenTicket;
use App\Models\Modifier;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\Sale;
use App\Models\SelfOrder;
use App\Models\SelfOrderItem;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use App\Support\CurrentOutlet;
use App\Support\CurrentTenant;
use App\Support\Features;
use App\Support\PosSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Siklus pesanan QR. Pesanan tidak punya tagihan sendiri: yang dibayar di kasir masuk open bill lewat
 * HeldOrderService::hold(), yang dibayar QRIS langsung menjadi Sale lewat SaleService::checkout().
 * Semua method dipanggil dengan CurrentTenant dan CurrentOutlet sudah menunjuk outlet pesanan.
 */
class SelfOrderService
{
    public const MAX_LINES = 50;

    public const MAX_PENDING_PER_TABLE = 3;

    public const MAX_PENDING_PER_DEVICE = 2;

    /**
     * Jaring pengaman terakhir bila QR disalahgunakan dari banyak perangkat: kotak masuk kasir tidak dibanjiri.
     */
    public const MAX_PENDING_PER_OUTLET = 60;

    public function __construct(
        private DocumentNumberGenerator $numbers,
        private QueueNumberService $queue,
        private StockDemandResolver $demands,
        private ModifierService $modifiers,
        private HeldOrderService $heldOrders,
        private KitchenTicketService $kitchen,
    ) {}

    /**
     * Menu yang boleh dipesan dari QR: sama dengan katalog kasir, tanpa obat wajib resep dan barang bernomor seri.
     *
     * @return Collection<int, Product>
     */
    public function catalog(int $outletId): Collection
    {
        $variants = Features::enabledAt('business.variants', $outletId);
        $relations = ['priceTiers', 'modifierGroups.modifiers', 'category'];

        $products = Product::query()
            ->withOutletData($outletId)
            ->where('is_active', true)
            ->sellableAt($outletId)
            ->orderableByGuests()
            ->when($variants, fn ($query) => $query->whereNull('parent_id'))
            ->with($relations)
            ->orderBy('name')
            ->get();

        $parents = $variants ? $products->filter(fn (Product $product) => $product->isVariantParent()) : collect();

        if ($parents->isNotEmpty()) {
            $children = Product::query()->withOutletData($outletId)->where('is_active', true)->orderableByGuests()->with($relations)
                ->whereIn('parent_id', $parents->pluck('id'))->orderBy('name')->get()->groupBy('parent_id');

            $parents->each(fn (Product $parent) => $parent->setRelation('variants', $children->get($parent->id, new Collection)));
        }

        $all = $products->concat($products->flatMap(fn (Product $product) => $product->relationLoaded('variants') ? $product->variants : []));
        $nearExpiry = BatchService::nearExpiryQuantities($all->pluck('id'), $outletId);
        $all->each(fn (Product $product) => $product->setAttribute('near_expiry_quantity', $nearExpiry[$product->id] ?? 0.0));

        // Induk varian tanpa anak yang bisa dipesan tidak ada gunanya ditampilkan.
        return $products->reject(fn (Product $product) => $variants && $product->isVariantParent() && $product->variants->isEmpty())->values();
    }

    /**
     * Total keranjang pelanggan dihitung server dari harga, harga grosir, dan potongan ED dekat saat ini.
     * Browser hanya mengirim produk, jumlah, pilihan tambahan, dan catatan.
     *
     * @param  list<array{product_id: int, quantity: float|int, modifiers?: list<int>, note?: ?string}>  $items
     * @return array{lines: list<array<string, mixed>>, subtotal: int, service_charge_rate: float, service_charge_amount: int, tax_rate: float, tax_label: string, tax_amount: int, total: int}
     *
     * @throws PosException
     */
    public function quote(array $items, string $orderType, int $outletId): array
    {
        if ($items === []) {
            throw new PosException('Keranjang masih kosong.', 'validation');
        }

        if (count($items) > self::MAX_LINES) {
            throw new PosException('Satu pesanan maksimal '.self::MAX_LINES.' baris menu. Kirim sisanya sebagai pesanan berikutnya.', 'validation');
        }

        $productIds = collect($items)->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values();
        $products = Product::query()->withOutletData($outletId)->where('is_active', true)->sellableAt($outletId)->orderableByGuests()
            ->whereIn('products.id', $productIds)->get()->keyBy('id');
        $variants = Features::enabledAt('business.variants', $outletId);
        $missing = $productIds->reject(fn (int $id) => $products->has($id) && ! ($variants && $products->get($id)->isVariantParent()))->values();

        if ($missing->isNotEmpty()) {
            throw new PosException('Ada menu yang sudah tidak tersedia. Menu itu dikeluarkan dari keranjang.', 'unavailable', ['product_ids' => $missing->all()]);
        }

        $useModifiers = Features::enabledAt('business.modifiers', $outletId);
        $chosenIds = collect($items)->flatMap(fn (array $item) => $item['modifiers'] ?? [])->map(fn ($id) => (int) $id)->unique();
        $modifierModels = $useModifiers && $chosenIds->isNotEmpty() ? Modifier::withTrashed()->with('group')->whereIn('id', $chosenIds)->get()->keyBy('id') : new Collection;
        $prepared = collect($items)->map(fn (array $item) => [
            'product_id' => (int) $item['product_id'],
            'quantity' => (float) $item['quantity'],
            'note' => filled($item['note'] ?? null) ? mb_substr(trim((string) $item['note']), 0, 150) : null,
            // Harga pilihan diisi dari database supaya ModifierService hanya menolak pilihan yang tidak berlaku.
            'modifiers' => $useModifiers ? collect($item['modifiers'] ?? [])->map(fn ($id) => ['id' => (int) $id, 'price' => (int) ($modifierModels->get((int) $id)?->price ?? 0)])->values()->all() : [],
        ])->values()->all();

        $modifierLines = $useModifiers ? $this->modifiers->resolve($prepared, $products, $modifierModels, false) : ['lines' => []];

        $tiers = Features::enabledAt('business.tiered-price', $outletId)
            ? ProductPriceTier::query()->whereIn('product_id', $productIds)->orderBy('min_quantity')->get()->groupBy('product_id')
                ->map(fn ($rows) => $rows->map(fn (ProductPriceTier $tier) => ['min' => (float) $tier->min_quantity, 'price' => (int) $tier->price])->values()->all())
            : new Collection;
        $tierQuantities = collect($prepared)->groupBy('product_id')->map(fn ($rows) => (float) $rows->sum('quantity'));
        $priceOf = fn (Product $product): int => CartCalculator::tierPrice($product->effectivePrice(), $tiers->get($product->id, []), (float) ($tierQuantities[$product->id] ?? 0));

        $nearLeft = BatchService::nearExpiryQuantities($productIds, $outletId);
        $nearPercent = PosSettings::nearExpiryDiscountPercent();
        $lines = [];

        foreach ($prepared as $index => $item) {
            $product = $products->get($item['product_id']);
            $price = $priceOf($product);
            $discount = 0;

            if (isset($nearLeft[$product->id])) {
                $discount = CartCalculator::nearExpiryDiscount($price, $item['quantity'], $nearLeft[$product->id], $nearPercent);
                $nearLeft[$product->id] = round(max(0, $nearLeft[$product->id] - $item['quantity']), 3);
            }

            $lines[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit' => $product->unit,
                'price' => $price,
                'quantity' => $item['quantity'],
                'modifiers' => $modifierLines['lines'][$index]['snapshot'] ?? [],
                'modifiers_total' => $modifierLines['lines'][$index]['total'] ?? 0,
                'discount' => $discount,
                'note' => $item['note'],
            ];
        }

        $taxRate = PosSettings::taxRate();
        $serviceRate = PosSettings::serviceChargeRate($orderType);
        $totals = CartCalculator::calculate(
            array_map(fn (array $line) => ['price' => $line['price'], 'modifiers' => $line['modifiers_total'], 'quantity' => $line['quantity'], 'discount' => $line['discount']], $lines),
            null, 0, $taxRate, $serviceRate,
        );

        foreach ($lines as $index => $line) {
            $lines[$index]['discount'] = $totals['lines'][$index]['discount'];
            $lines[$index]['total'] = $totals['lines'][$index]['total'];
        }

        return [
            'lines' => $lines,
            'subtotal' => $totals['subtotal'],
            'service_charge_rate' => $serviceRate,
            'service_charge_amount' => $totals['service_charge_amount'],
            'tax_rate' => $taxRate,
            'tax_label' => PosSettings::taxLabel(),
            'tax_amount' => $totals['tax_amount'],
            'total' => $totals['total'],
        ];
    }

    /**
     * QRIS hanya ditawarkan bila toko punya QRIS yang valid dan metode QRIS aktif di outlet.
     */
    public function qrisAvailable(): bool
    {
        return PosSettings::qrisPayload() !== null && in_array(PaymentMethod::Qris, PosSettings::paymentMethods(), true);
    }

    /**
     * @param  array{client_uuid: string, customer_name: string, customer_phone?: ?string, payment_method?: ?string, notes?: ?string, items: list<array<string, mixed>>}  $data
     *
     * @throws PosException
     */
    public function submit(Outlet $outlet, ?DiningTable $table, ?string $deviceId, array $data): SelfOrder
    {
        if ($existing = SelfOrder::query()->where('client_uuid', $data['client_uuid'])->first()) {
            return $existing;
        }

        if ($reason = SelfOrderGate::closedReason($outlet->id)) {
            throw new PosException(SelfOrderGate::message($reason), 'closed');
        }

        if ($quotaMessage = $this->quotaMessage($outlet, $table, $deviceId)) {
            throw new PosException($quotaMessage, 'quota');
        }

        $orderType = $table ? OrderType::DineIn->value : OrderType::TakeAway->value;
        $quote = $this->quote($data['items'], $orderType, $outlet->id);
        $this->ensureStock($quote['lines'], $outlet->id);

        $qris = ($data['payment_method'] ?? null) === SelfOrder::METHOD_QRIS && $this->qrisAvailable();

        try {
            $order = DB::transaction(function () use ($outlet, $table, $deviceId, $data, $orderType, $quote, $qris) {
                $order = SelfOrder::create([
                    'outlet_id' => $outlet->id,
                    'dining_table_id' => $table?->id,
                    'number' => $this->numbers->next('SO', 6, null, $outlet),
                    'client_uuid' => $data['client_uuid'],
                    'session_token' => Str::random(64),
                    'device_id' => $deviceId,
                    'order_type' => $orderType,
                    'table_label' => $table?->name,
                    'queue_number' => $this->queue->next($outlet->id),
                    'customer_name' => mb_substr(trim($data['customer_name']), 0, 100),
                    'customer_phone' => filled($data['customer_phone'] ?? null) ? mb_substr(preg_replace('/[^0-9+]/', '', (string) $data['customer_phone']) ?? '', 0, 25) : null,
                    'payment_method' => $qris ? SelfOrder::METHOD_QRIS : SelfOrder::METHOD_CASHIER,
                    'payment_status' => $qris ? SelfOrder::PAYMENT_PENDING_VERIFICATION : SelfOrder::PAYMENT_UNPAID,
                    'status' => SelfOrder::STATUS_PENDING,
                    'subtotal' => $quote['subtotal'],
                    'service_charge_rate' => $quote['service_charge_rate'],
                    'service_charge_amount' => $quote['service_charge_amount'],
                    'tax_rate' => $quote['tax_rate'],
                    'tax_amount' => $quote['tax_amount'],
                    'total' => $quote['total'],
                    'notes' => filled($data['notes'] ?? null) ? mb_substr(trim((string) $data['notes']), 0, 500) : null,
                ]);

                foreach ($quote['lines'] as $line) {
                    $order->items()->create([
                        'product_id' => $line['product_id'],
                        'product_name' => $line['product_name'],
                        'unit' => $line['unit'],
                        'price' => $line['price'],
                        'quantity' => $line['quantity'],
                        'modifiers' => $line['modifiers'] !== [] ? $line['modifiers'] : null,
                        'modifiers_total' => $line['modifiers_total'],
                        'discount_amount' => $line['discount'],
                        'total' => $line['total'],
                        'note' => $line['note'],
                    ]);
                }

                if (PosSettings::selfOrderKitchenFlow() === 'auto') {
                    $this->sendToKitchen($order, $this->shiftOwner($outlet->id));
                }

                return $order;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Ketukan ganda: dua request dengan client_uuid sama lolos cek di atas bersamaan.
            return SelfOrder::query()->where('client_uuid', $data['client_uuid'])->first() ?? throw $exception;
        }

        SelfOrderChanged::dispatch($order, true);

        return $order;
    }

    /**
     * Alasan pesanan baru belum bisa diterima karena kuota pesanan menunggu sudah penuh, atau null bila masih ada ruang.
     */
    public function quotaMessage(Outlet $outlet, ?DiningTable $table, ?string $deviceId): ?string
    {
        $pending = fn () => SelfOrder::query()->where('outlet_id', $outlet->id)->where('status', SelfOrder::STATUS_PENDING);

        if ($pending()->count() >= self::MAX_PENDING_PER_OUTLET) {
            return 'Kasir sedang menangani banyak pesanan. Coba lagi beberapa menit lagi atau pesan langsung ke kasir.';
        }

        if ($table && $pending()->where('dining_table_id', $table->id)->count() >= self::MAX_PENDING_PER_TABLE) {
            return 'Meja ini masih punya pesanan yang menunggu konfirmasi kasir. Tunggu sebentar atau panggil pelayan.';
        }

        if ($deviceId !== null && $pending()->where('device_id', $deviceId)->count() >= self::MAX_PENDING_PER_DEVICE) {
            return 'Pesanan Anda sebelumnya masih menunggu konfirmasi kasir. Tunggu sampai diproses sebelum memesan lagi.';
        }

        return null;
    }

    /**
     * Kasir menerima pesanan makan di tempat yang dibayar belakangan: masuk open bill meja dan dapur.
     *
     * @throws PosException
     */
    public function accept(SelfOrder $order, User $user): SelfOrder
    {
        return DB::transaction(function () use ($order, $user) {
            $locked = $this->lock($order);

            if ($this->nextAction($locked) !== 'accept') {
                throw new PosException('Pesanan ini tidak bisa diterima ke open bill. Muat ulang daftar pesanan.', 'self_order_state');
            }

            $this->ensureStock($locked->items->map(fn (SelfOrderItem $item) => ['product_id' => $item->product_id, 'quantity' => $item->quantity, 'modifiers' => $item->modifiers ?? []])->all(), $locked->outlet_id);
            $this->sendToKitchen($locked, $user);
            $this->announce($locked);

            return $locked;
        });
    }

    /**
     * Keranjang kasir untuk *Proses Bayar*: baris pesanan dengan harga snapshot, tipe pesanan, dan mejanya.
     *
     * @return array<string, mixed>
     *
     * @throws PosException
     */
    public function cartFor(SelfOrder $order): array
    {
        if (! $order->isOpen() || $order->isPaid() || $order->sale_id !== null || $order->held_order_id !== null) {
            throw new PosException('Pesanan ini sudah diproses atau dibatalkan.', 'self_order_state');
        }

        // Catatan transaksi dibatasi 255 karakter di checkout dan transaksi tertunda.
        $note = mb_substr(implode(' / ', array_filter(["QR {$order->number} · {$order->customer_name}", $order->notes])), 0, 255);

        return [
            'self_order_id' => $order->id,
            'number' => $order->number,
            'label' => $order->label(),
            'customer_name' => $order->customer_name,
            'orderType' => $order->order_type,
            'table' => (string) $order->table_label,
            'kitchenSent' => $order->kitchen_sent ?? [],
            'note' => $note,
            'total' => $order->total,
            'items' => $this->cartItems($order),
        ];
    }

    /**
     * Kasir sudah melihat uangnya di mutasi QRIS: pesanan menjadi Sale lunas. Aman diulang; verifikasi
     * kedua mengembalikan Sale yang sama.
     *
     * @throws PosException
     */
    public function verifyPayment(SelfOrder $order, User $user): Sale
    {
        $order->refresh();

        if ($order->sale_id !== null && $order->sale) {
            return $order->sale;
        }

        if (! $order->isQris() || $order->payment_status !== SelfOrder::PAYMENT_PENDING_VERIFICATION || ! $order->isOpen()) {
            throw new PosException('Pesanan ini tidak sedang menunggu verifikasi pembayaran.', 'self_order_state');
        }

        $sale = app(SaleService::class)->checkout($user, [
            'client_uuid' => $order->client_uuid,
            'items' => $order->items->map(fn (SelfOrderItem $item) => [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'discount' => 0,
                'auto_discount' => $item->discount_amount,
                'note' => $item->note,
                'modifiers' => collect($item->modifiers ?? [])->map(fn (array $row) => ['id' => (int) $row['id'], 'price' => (int) $row['price'], 'name' => $row['name'] ?? null])->all(),
                'self_order_item_id' => $item->id,
            ])->all(),
            'self_order_ids' => [$order->id],
            'order_type' => $order->order_type,
            'table_label' => $order->table_label,
            'kitchen_sent' => $order->kitchen_sent ?? [],
            'payments' => [['method' => PaymentMethod::Qris->value, 'amount' => $order->total, 'reference' => $order->number]],
            'note' => trim("Pesanan QR {$order->number} · {$order->customer_name}"),
            'expected_total' => $order->total,
        ]);

        return $sale;
    }

    /**
     * Uang QRIS tidak ditemukan di mutasi. Pesanan tetap terbuka sampai kasir memilih menagih di kasir atau membatalkan.
     *
     * @throws PosException
     */
    public function rejectPayment(SelfOrder $order): SelfOrder
    {
        return DB::transaction(function () use ($order) {
            $locked = $this->lock($order);

            if (! $locked->isQris() || $locked->payment_status !== SelfOrder::PAYMENT_PENDING_VERIFICATION || ! $locked->isOpen()) {
                throw new PosException('Pesanan ini tidak sedang menunggu verifikasi pembayaran.', 'self_order_state');
            }

            $locked->update(['payment_status' => SelfOrder::PAYMENT_REJECTED]);
            $this->announce($locked);

            return $locked;
        });
    }

    /**
     * Pembayaran QRIS ditolak tapi pelanggan tetap mau memesan: ditagih di kasir. Pesanan yang sudah di
     * dapur masuk open bill dengan tanda dapurnya, jadi tidak terbit tiket kedua.
     *
     * @throws PosException
     */
    public function billAtCashier(SelfOrder $order, User $user): SelfOrder
    {
        return DB::transaction(function () use ($order, $user) {
            $locked = $this->lock($order);

            if ($locked->payment_status !== SelfOrder::PAYMENT_REJECTED || ! $locked->isOpen()) {
                throw new PosException('Hanya pesanan dengan pembayaran QRIS yang ditolak yang bisa dialihkan ke kasir.', 'self_order_state');
            }

            $locked->update(['payment_method' => SelfOrder::METHOD_CASHIER, 'payment_status' => SelfOrder::PAYMENT_UNPAID]);

            if (! $locked->isPending()) {
                $this->openBill($locked, $user);
            }

            $this->announce($locked);

            return $locked;
        });
    }

    /**
     * Ditolak kasir. Tiket yang belum dikerjakan ditarik dari dapur dan barisnya dikeluarkan dari open bill.
     *
     * @throws PosException
     */
    public function cancel(SelfOrder $order, string $reason, string $status = SelfOrder::STATUS_CANCELLED): SelfOrder
    {
        return DB::transaction(function () use ($order, $reason, $status) {
            $locked = $this->lock($order);

            if (! $locked->isOpen() || $locked->isPaid()) {
                throw new PosException('Pesanan ini sudah selesai, dibayar, atau dibatalkan.', 'self_order_state');
            }

            $this->kitchen->cancelPending($locked->id);
            $this->removeFromOpenBill($locked);

            $locked->update([
                'status' => $status,
                'cancelled_at' => now(),
                'cancel_reason' => mb_substr(trim($reason), 0, 150) ?: null,
                'held_order_id' => null,
            ]);
            $this->announce($locked);

            return $locked;
        });
    }

    /**
     * Pelanggan hanya boleh membatalkan selama kasir belum menanggapi.
     *
     * @throws PosException
     */
    public function cancelByCustomer(SelfOrder $order): SelfOrder
    {
        if (! $order->isPending()) {
            throw new PosException('Pesanan sudah diproses, jadi tidak bisa dibatalkan dari sini. Hubungi kasir.', 'self_order_state');
        }

        return $this->cancel($order, 'Dibatalkan pelanggan');
    }

    /**
     * @throws PosException
     */
    public function markServed(SelfOrder $order): SelfOrder
    {
        return DB::transaction(function () use ($order) {
            $locked = $this->lock($order);

            if (! in_array($locked->status, [SelfOrder::STATUS_READY, SelfOrder::STATUS_IN_KITCHEN], true)) {
                throw new PosException('Pesanan ini belum masuk dapur atau sudah selesai.', 'self_order_state');
            }

            $locked->update(['status' => SelfOrder::STATUS_SERVED, 'served_at' => now()]);
            $this->announce($locked);

            return $locked;
        });
    }

    /**
     * Status pemenuhan mengikuti tiket dapurnya: semua selesai berarti siap, ada yang dibuka lagi berarti kembali disiapkan.
     */
    public function refreshFulfilment(int $selfOrderId): void
    {
        $order = SelfOrder::query()->withoutGlobalScopes()->find($selfOrderId);

        if (! $order || ! in_array($order->status, [SelfOrder::STATUS_IN_KITCHEN, SelfOrder::STATUS_READY], true)) {
            return;
        }

        $tickets = KitchenTicket::query()->withoutGlobalScopes()->where('self_order_id', $order->id)->where('status', '!=', KitchenTicket::STATUS_CANCELLED)->pluck('status');
        $ready = $tickets->isNotEmpty() && $tickets->every(fn (string $status) => $status === KitchenTicket::STATUS_DONE);
        $status = $ready ? SelfOrder::STATUS_READY : SelfOrder::STATUS_IN_KITCHEN;

        if ($status === $order->status) {
            return;
        }

        $order->update(['status' => $status, 'ready_at' => $ready ? now() : null]);
        $this->announce($order);

        if ($ready) {
            SelfOrderReady::dispatch($order);
        }
    }

    /**
     * Dipanggil SaleService setelah transaksi tersimpan: pesanan lunas, tiketnya ditautkan ke transaksi.
     *
     * @param  list<int>  $selfOrderIds
     */
    public function attachSale(array $selfOrderIds, Sale $sale): void
    {
        if ($selfOrderIds === []) {
            return;
        }

        KitchenTicket::query()->whereIn('self_order_id', $selfOrderIds)->whereNull('sale_id')->update(['sale_id' => $sale->id]);

        foreach (SelfOrder::query()->whereIn('id', $selfOrderIds)->get() as $order) {
            $order->update([
                'sale_id' => $sale->id,
                'held_order_id' => null,
                'payment_status' => SelfOrder::PAYMENT_PAID,
                'paid_at' => now(),
                'status' => $order->isPending() ? SelfOrder::STATUS_IN_KITCHEN : $order->status,
                'accepted_at' => $order->accepted_at ?? now(),
            ]);

            $this->refreshFulfilment($order->id);
            $this->announce($order->refresh());
        }
    }

    /**
     * Pesanan QR yang masih terbuka bila shift ini satu-satunya yang berjalan di outletnya; setelah ditutup
     * tidak ada kasir yang menanggapi, jadi penutup shift diperingatkan dulu.
     *
     * @return Collection<int, SelfOrder>
     */
    public function openWhenLastShiftCloses(CashShift $shift): Collection
    {
        $others = CashShift::query()->withoutGlobalScopes()->where('outlet_id', $shift->outlet_id)->whereNull('closed_at')->whereKeyNot($shift->id)->exists();

        if (! $shift->isOpen() || $others) {
            return new Collection;
        }

        return SelfOrder::query()->withoutGlobalScopes()->where('outlet_id', $shift->outlet_id)->open()->oldest()->get()->toBase();
    }

    public function serveReadyPaid(int $outletId): void
    {
        SelfOrder::query()->withoutGlobalScopes()->where('outlet_id', $outletId)
            ->where('status', SelfOrder::STATUS_READY)->where('payment_status', SelfOrder::PAYMENT_PAID)
            ->get()
            ->each(function (SelfOrder $order) {
                $order->update(['status' => SelfOrder::STATUS_SERVED, 'served_at' => now()]);
                SelfOrderStatusUpdated::dispatch($order);
            });
    }

    /**
     * Pesanan yang tidak ditanggapi kasir sampai batas waktu outletnya. Pesanan QRIS yang menunggu
     * verifikasi tidak ikut: pelanggannya mungkin sudah mentransfer, jadi harus diputuskan kasir.
     */
    public function expireStale(): int
    {
        $expired = 0;
        $pending = SelfOrder::query()->withoutGlobalScopes()
            ->where('status', SelfOrder::STATUS_PENDING)
            ->where('payment_status', '!=', SelfOrder::PAYMENT_PENDING_VERIFICATION)
            ->where('updated_at', '<', now()->subMinute())
            ->get()
            ->groupBy('outlet_id');

        foreach ($pending as $outletId => $orders) {
            $tenantId = (int) $orders->first()->tenant_id;

            $expired += app(CurrentTenant::class)->run($tenantId, fn () => app(CurrentOutlet::class)->run((int) $outletId, function () use ($orders) {
                $cutoff = now()->subMinutes(PosSettings::selfOrderExpireMinutes());
                $count = 0;

                // Dihitung dari perubahan terakhir: pesanan yang baru dialihkan kasir ke bayar di kasir mendapat waktu lagi.
                foreach ($orders->filter(fn (SelfOrder $order) => $order->updated_at->lt($cutoff)) as $order) {
                    try {
                        $this->cancel($order, 'Tidak ditanggapi kasir sampai batas waktu', SelfOrder::STATUS_EXPIRED);
                        $count++;
                    } catch (PosException) {
                        // sudah ditangani kasir di antara pembacaan dan pembatalan
                    }
                }

                return $count;
            }));
        }

        return $expired;
    }

    /**
     * Langkah berikutnya untuk kasir: `verify` (QRIS menunggu verifikasi), `decide` (QRIS ditolak),
     * `accept` (masuk open bill), `pay` (muat ke keranjang lalu bayar), atau null bila tinggal menunggu dapur.
     */
    public function nextAction(SelfOrder $order): ?string
    {
        if (! $order->isOpen() || $order->isPaid()) {
            return null;
        }

        if ($order->payment_status === SelfOrder::PAYMENT_PENDING_VERIFICATION) {
            return 'verify';
        }

        if ($order->payment_status === SelfOrder::PAYMENT_REJECTED) {
            return 'decide';
        }

        if (! $order->isPending()) {
            return null;
        }

        return $order->isDineIn() && PosSettings::selfOrderDineInPayment() === 'after' ? 'accept' : 'pay';
    }

    /**
     * Keadaan pesanan untuk halaman pelacakan pelanggan.
     *
     * @return array<string, mixed>
     */
    public function state(SelfOrder $order): array
    {
        $order->loadMissing('items');

        return [
            'number' => $order->number,
            'label' => $order->label(),
            'queue_number' => $order->queue_number,
            'table' => $order->table_label,
            'order_type' => $order->order_type,
            'customer_name' => $order->customer_name,
            'status' => $order->status,
            'status_label' => $order->statusLabel(),
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'payment_label' => $order->paymentLabel(),
            'cancel_reason' => $order->cancel_reason,
            'cancellable' => $order->isPending() && ! $order->isPaid(),
            'show_qris' => $order->isQris() && $order->payment_status === SelfOrder::PAYMENT_PENDING_VERIFICATION && $order->isOpen() && PosSettings::qrisPayload() !== null,
            'items' => $order->items->map(fn (SelfOrderItem $item) => [
                'name' => $item->product_name,
                'quantity' => $item->quantity,
                'modifiers' => collect($item->modifiers ?? [])->pluck('name')->filter()->values()->all(),
                'note' => $item->note,
                'total' => $item->total,
            ])->all(),
            'subtotal' => $order->subtotal,
            'service_charge_amount' => $order->service_charge_amount,
            'tax_amount' => $order->tax_amount,
            'total' => $order->total,
            'created_at' => $order->created_at->toIso8601String(),
        ];
    }

    /**
     * Tiket dapur untuk pesanan ini. Bayar di kasir: lewat open bill. QRIS: tiket langsung, dan tanda
     * dapurnya disimpan supaya checkout saat verifikasi tidak menerbitkan tiket lagi.
     *
     * @throws PosException
     */
    private function sendToKitchen(SelfOrder $order, User $user): void
    {
        if ($order->isQris()) {
            $items = $this->cartItems($order);
            $this->kitchen->send($items, [], $order->label().' · QR', $order->order_type, $user, null, $order->outlet_id, $order->id);
            $order->update(['status' => SelfOrder::STATUS_IN_KITCHEN, 'accepted_at' => now(), 'kitchen_sent' => KitchenTicketService::delta($items, [])['sent']]);

            return;
        }

        $this->openBill($order, $user);
        $order->update(['status' => SelfOrder::STATUS_IN_KITCHEN, 'accepted_at' => now()]);
    }

    private function openBill(SelfOrder $order, User $user): void
    {
        $result = $this->heldOrders->hold($user, [
            'items' => $this->cartItems($order),
            'orderType' => $order->order_type,
            'table' => (string) $order->table_label,
            'customer' => null,
            'discountType' => null,
            'discountValue' => 0,
            'note' => filled($order->notes) ? mb_substr("{$order->customer_name}: {$order->notes}", 0, 120) : null,
            'kitchenSent' => $order->kitchen_sent ?? [],
            'total' => $order->total,
        ], $order->label().' · '.$order->customer_name, $order);

        $order->update(['held_order_id' => $result['order']->id]);
    }

    private function removeFromOpenBill(SelfOrder $order): void
    {
        $held = $order->held_order_id ? HeldOrder::query()->whereKey($order->held_order_id)->lockForUpdate()->first() : null;

        if (! $held) {
            return;
        }

        $itemIds = $order->items->pluck('id')->all();
        $cart = $held->cart;
        $cart['items'] = array_values(array_filter($cart['items'] ?? [], fn ($line) => is_array($line) && ! in_array((int) ($line['self_order_item_id'] ?? 0), $itemIds, true)));

        if ($cart['items'] === []) {
            $held->delete();

            return;
        }

        $cart['kitchenSent'] = KitchenTicketService::delta($cart['items'], [])['sent'];
        $cart['total'] = max(0, (int) ($cart['total'] ?? 0) - $order->total);
        $held->update(['cart' => $cart, 'item_count' => count($cart['items']), 'total' => $cart['total']]);
    }

    /**
     * Baris keranjang berbentuk sama dengan kasir web/mobile, supaya open bill-nya bisa dilanjutkan di keduanya.
     *
     * @return list<array<string, mixed>>
     */
    private function cartItems(SelfOrder $order): array
    {
        $products = Product::withTrashed()->withOutletData($order->outlet_id)->whereIn('products.id', $order->items->pluck('product_id'))->get()->keyBy('id');

        return $order->items->map(function (SelfOrderItem $item) use ($products, $order) {
            $product = $products->get($item->product_id);

            return [
                ...$item->toCartItem(),
                'key' => (string) Str::uuid(),
                'sku' => $product?->sku,
                'base_unit' => (string) $item->unit,
                'base_price' => $item->price,
                'unit_id' => null,
                'factor' => 1,
                'units' => [],
                'serial' => false,
                'serials' => [],
                'track' => (bool) $product?->track_stock,
                'stock' => $product?->track_stock ? $product->outletStock() : null,
                'rx' => false,
                'tiers' => [],
                'near_expiry' => 0,
                'modifier_groups' => [],
                'self_order_id' => $order->id,
                'self_order_quantity' => $item->quantity,
                'self_order_label' => $order->number,
            ];
        })->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws PosException
     */
    private function ensureStock(array $lines, int $outletId): void
    {
        try {
            $this->demands->ensureCartAvailable($lines, $outletId);
        } catch (PosException $exception) {
            $name = $exception->context['product_name'] ?? 'salah satu menu';

            throw new PosException("Maaf, {$name} baru saja habis.", 'insufficient_stock', $exception->context);
        }
    }

    /**
     * Pemilik shift terbuka paling lama di outlet; SelfOrderGate menjamin selalu ada saat pesanan diterima.
     *
     * @throws PosException
     */
    private function shiftOwner(int $outletId): User
    {
        $shift = CashShift::query()->withoutGlobalScopes()->where('outlet_id', $outletId)->whereNull('closed_at')->oldest('opened_at')->first();

        return $shift?->user ?? throw new PosException(SelfOrderGate::message('no_shift'), 'closed');
    }

    private function lock(SelfOrder $order): SelfOrder
    {
        return SelfOrder::query()->with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    private function announce(SelfOrder $order): void
    {
        SelfOrderStatusUpdated::dispatch($order);
        SelfOrderChanged::dispatch($order);
    }
}
