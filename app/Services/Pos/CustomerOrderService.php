<?php

namespace App\Services\Pos;

use App\Enums\CashMovementType;
use App\Enums\CustomerOrderStatus;
use App\Enums\PaymentMethod;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderPayment;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\NumberFormatter;
use App\Support\PosSettings;
use Illuminate\Support\Facades\DB;

/**
 * Pesanan dengan uang muka dan tiket servis. DP tunai masuk laci shift penerimanya sebagai kas masuk; saat
 * pesanan diambil, kasir melunasinya lewat checkout biasa yang membawa customer_order_id (lihat SaleService).
 */
class CustomerOrderService
{
    public function __construct(private DocumentNumberGenerator $numbers) {}

    /**
     * @param  array{type?: string, customer_id?: ?int, customer_name: string, customer_phone?: ?string, pickup_at?: ?string, device?: ?string, device_serial?: ?string, complaint?: ?string, notes?: ?string, items?: list<array{product_id?: ?int, name?: ?string, quantity: float|int|string, price: int|string, note?: ?string}>, deposit?: int, deposit_method?: ?string, deposit_reference?: ?string}  $data
     *
     * @throws PosException
     */
    public function create(User $user, array $data): CustomerOrder
    {
        $outletId = app(CurrentOutlet::class)->idOrPrimary() ?? throw new PosException('Toko belum punya outlet.');
        app(CurrentOutlet::class)->ensureOperational($outletId);

        if (! Features::enabledAt('business.pre-order', $outletId)) {
            throw new PosException('Pesanan & Servis tidak aktif di outlet ini. Ganti outlet atau aktifkan di pengaturan fitur outlet.', 'feature_off_at_outlet');
        }

        return DB::transaction(function () use ($user, $data, $outletId) {
            $customer = isset($data['customer_id']) ? Customer::query()->find($data['customer_id']) : null;
            $items = $this->items($data['items'] ?? []);
            $type = ($data['type'] ?? null) === CustomerOrder::TYPE_SERVICE ? CustomerOrder::TYPE_SERVICE : CustomerOrder::TYPE_ORDER;

            $order = CustomerOrder::query()->create([
                'outlet_id' => $outletId,
                'number' => $this->numbers->next($type === CustomerOrder::TYPE_SERVICE ? 'SRV' : 'PSN', 5, null, Outlet::query()->find($outletId)),
                'type' => $type,
                'status' => CustomerOrderStatus::New,
                'customer_id' => $customer?->id,
                'customer_name' => trim((string) ($data['customer_name'] ?? '')) ?: ($customer?->name ?? 'Pelanggan'),
                'customer_phone' => $data['customer_phone'] ?? $customer?->phone,
                'pickup_at' => $data['pickup_at'] ?? null,
                'estimated_total' => (int) collect($items)->sum(fn (array $item) => round($item['price'] * $item['quantity'])),
                'device' => $data['device'] ?? null,
                'device_serial' => $data['device_serial'] ?? null,
                'complaint' => $data['complaint'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($items as $item) {
                $order->items()->create($item);
            }

            if ((int) ($data['deposit'] ?? 0) > 0) {
                $this->pay($order, $user, (int) $data['deposit'], PaymentMethod::from($data['deposit_method'] ?? PaymentMethod::Cash->value), $data['deposit_reference'] ?? null);
            }

            activity()->performedOn($order)->causedBy($user)->event('created')->log("Pesanan {$order->number} untuk {$order->customer_name} dicatat.");

            return $order->refresh();
        });
    }

    /**
     * Ganti daftar barang/jasa pesanan yang masih terbuka; total perkiraan ikut dihitung ulang.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function replaceItems(CustomerOrder $order, array $items): CustomerOrder
    {
        $this->ensureOpen($order);

        return DB::transaction(function () use ($order, $items) {
            $rows = $this->items($items);
            $order->items()->delete();

            foreach ($rows as $row) {
                $order->items()->create($row);
            }

            $order->update(['estimated_total' => (int) collect($rows)->sum(fn (array $item) => round($item['price'] * $item['quantity']))]);

            return $order->refresh();
        });
    }

    /**
     * Terima uang muka. Tunai wajib ada shift terbuka supaya uangnya tercatat di laci.
     *
     * @throws PosException
     */
    public function pay(CustomerOrder $order, User $user, int $amount, PaymentMethod $method, ?string $reference = null): CustomerOrderPayment
    {
        $this->ensureOpen($order);

        if ($amount <= 0) {
            throw new PosException('Nominal uang muka harus lebih dari 0.');
        }

        if (! in_array($method, PosSettings::paymentMethods(), true)) {
            throw new PosException("Metode {$method->label()} sedang tidak aktif.");
        }

        return DB::transaction(function () use ($order, $user, $amount, $method, $reference) {
            $shift = $user->openShift();

            if ($method === PaymentMethod::Cash && ! $shift) {
                throw new PosException('Buka shift kasir dulu untuk menerima uang muka tunai.', 'no_shift');
            }

            if ($shift) {
                CashShift::query()->whereKey($shift->id)->lockForUpdate()->first();
            }

            $payment = $order->payments()->create([
                'outlet_id' => $shift?->outlet_id ?? $order->outlet_id,
                'cash_shift_id' => $shift?->id,
                'user_id' => $user->id,
                'kind' => CustomerOrderPayment::KIND_DEPOSIT,
                'method' => $method,
                'amount' => $amount,
                'reference' => filled($reference) ? $reference : null,
                'paid_at' => now(),
            ]);

            if ($method === PaymentMethod::Cash) {
                CashMovement::create([
                    'outlet_id' => $shift->outlet_id,
                    'cash_shift_id' => $shift->id,
                    'user_id' => $user->id,
                    'type' => CashMovementType::In,
                    'amount' => $amount,
                    'reason' => "DP {$order->number}",
                    'reference_type' => $order->getMorphClass(),
                    'reference_id' => $order->id,
                ]);
            }

            $order->update(['deposit' => (int) $order->payments()->sum('amount')]);

            return $payment;
        });
    }

    /**
     * @throws PosException
     */
    public function setStatus(CustomerOrder $order, CustomerOrderStatus $status): CustomerOrder
    {
        $this->ensureOpen($order);

        if (! in_array($status, [CustomerOrderStatus::New, CustomerOrderStatus::InProgress, CustomerOrderStatus::Ready], true)) {
            throw new PosException('Pesanan diselesaikan lewat pelunasan di kasir, atau dibatalkan dengan tombol Batalkan.');
        }

        $order->update(['status' => $status]);

        return $order;
    }

    /**
     * Batalkan pesanan. DP yang dikembalikan tunai keluar dari laci shift pembatal; DP yang tidak dikembalikan
     * tetap tercatat sebagai uang yang sudah diterima.
     *
     * @throws PosException
     */
    public function cancel(CustomerOrder $order, User $user, bool $refund, string $reason): CustomerOrder
    {
        $this->ensureOpen($order);

        if (trim($reason) === '') {
            throw new PosException('Tulis alasan pembatalan.');
        }

        return DB::transaction(function () use ($order, $user, $refund, $reason) {
            $locked = CustomerOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($refund && $locked->deposit > 0) {
                $byMethod = $locked->payments()->get()->groupBy(fn ($payment) => $payment->method->value)->map(fn ($rows) => (int) $rows->sum('amount'));
                $shift = $user->openShift();

                if (($byMethod[PaymentMethod::Cash->value] ?? 0) > 0) {
                    if (! $shift) {
                        throw new PosException('Buka shift kasir dulu supaya pengembalian DP tunai tercatat di laci.', 'no_shift');
                    }

                    CashShift::query()->whereKey($shift->id)->lockForUpdate()->first();

                    if ($byMethod[PaymentMethod::Cash->value] > $shift->summary()['expected']) {
                        throw new PosException('Uang di laci tidak cukup untuk mengembalikan DP tunai ('.NumberFormatter::currency($byMethod[PaymentMethod::Cash->value]).').');
                    }

                    CashMovement::create([
                        'outlet_id' => $shift->outlet_id,
                        'cash_shift_id' => $shift->id,
                        'user_id' => $user->id,
                        'type' => CashMovementType::Out,
                        'amount' => $byMethod[PaymentMethod::Cash->value],
                        'reason' => "Refund DP {$locked->number}",
                        'reference_type' => $locked->getMorphClass(),
                        'reference_id' => $locked->id,
                    ]);
                }

                foreach ($byMethod->filter(fn (int $amount) => $amount > 0) as $method => $amount) {
                    $locked->payments()->create([
                        'outlet_id' => $shift?->outlet_id ?? $locked->outlet_id,
                        'cash_shift_id' => $method === PaymentMethod::Cash->value ? $shift?->id : null,
                        'user_id' => $user->id,
                        'kind' => CustomerOrderPayment::KIND_REFUND,
                        'method' => $method,
                        'amount' => -$amount,
                        'paid_at' => now(),
                    ]);
                }
            }

            $locked->update([
                'status' => CustomerOrderStatus::Cancelled,
                'cancelled_at' => now(),
                'deposit' => (int) $locked->payments()->sum('amount'),
                'notes' => trim(($locked->notes ? $locked->notes."\n" : '').'Dibatalkan: '.trim($reason)),
            ]);

            activity()->performedOn($locked)->causedBy($user)->event('voided')->log("Pesanan {$locked->number} dibatalkan: ".trim($reason));

            return $locked;
        });
    }

    /**
     * Isi keranjang kasir untuk pelunasan: barang pesanan yang masih dijual, dengan harga katalog saat ini.
     *
     * @return array{customer_order_id: int, number: string, customer: ?array{id: int, name: string}, deposit: int, items: list<array{product_id: int, quantity: float, note: ?string}>, skipped: list<string>}
     */
    public function cartFor(CustomerOrder $order): array
    {
        $this->ensureOpen($order);
        $order->loadMissing('items');
        $products = Product::query()->whereIn('id', $order->items->pluck('product_id')->filter())->where('is_active', true)->pluck('id')->all();

        return [
            'customer_order_id' => $order->id,
            'number' => $order->number,
            'customer' => $order->customer_id ? ['id' => $order->customer_id, 'name' => $order->customer_name] : null,
            'deposit' => (int) $order->deposit,
            'items' => $order->items->filter(fn ($item) => in_array($item->product_id, $products, true))
                ->map(fn ($item) => ['product_id' => (int) $item->product_id, 'quantity' => (float) $item->quantity, 'note' => $item->note])->values()->all(),
            'skipped' => $order->items->reject(fn ($item) => in_array($item->product_id, $products, true))->pluck('name')->values()->all(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{product_id: ?int, name: string, quantity: float, price: int, note: ?string}>
     */
    private function items(array $items): array
    {
        $products = Product::query()->whereIn('id', collect($items)->pluck('product_id')->filter())->get()->keyBy('id');

        return collect($items)->map(function (array $item) use ($products) {
            $product = isset($item['product_id']) ? $products->get((int) $item['product_id']) : null;
            $name = trim((string) ($item['name'] ?? '')) ?: $product?->name;

            if ($name === null || $name === '') {
                throw new PosException('Setiap baris pesanan butuh produk atau nama barang/jasa.');
            }

            return [
                'product_id' => $product?->id,
                'name' => mb_substr($name, 0, 150),
                'quantity' => max(0.001, round((float) $item['quantity'], 3)),
                'price' => max(0, (int) ($item['price'] ?? $product?->effectivePrice() ?? 0)),
                'note' => filled($item['note'] ?? null) ? mb_substr((string) $item['note'], 0, 150) : null,
            ];
        })->values()->all();
    }

    private function ensureOpen(CustomerOrder $order): void
    {
        if (! $order->isOpen()) {
            throw new PosException("Pesanan {$order->number} sudah {$order->status->label()}.");
        }
    }
}
