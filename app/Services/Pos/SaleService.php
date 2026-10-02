<?php

namespace App\Services\Pos;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use App\Support\NumberFormatter;
use App\Support\PosSettings;
use App\Support\TenantRule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(
        private DocumentNumberGenerator $numbers,
        private StockService $stock,
    ) {}

    /**
     * Simpan transaksi dari layar kasir. Harga, stok, dan total dihitung ulang dari database;
     * data dari browser hanya dipakai untuk mendeteksi harga yang berubah sejak ditambahkan ke keranjang.
     * Request yang terkirim dua kali (tombol diketuk ganda, koneksi putus lalu dicoba lagi) memakai
     * client_uuid yang sama, jadi yang kedua mengembalikan transaksi pertama, bukan membuat transaksi baru.
     *
     * @param  array<string, mixed>  $payload
     */
    public function checkout(User $cashier, array $payload): Sale
    {
        $data = $this->validateCheckout($payload);

        if ($existing = Sale::query()->where('client_uuid', $data['client_uuid'])->first()) {
            if ($existing->user_id !== $cashier->id) {
                throw new PosException('Kode transaksi bentrok. Muat ulang halaman kasir lalu coba lagi.');
            }

            return $existing;
        }

        $shift = $cashier->openShift();

        if (! $shift) {
            throw new PosException('Shift kasir belum dibuka. Buka shift dulu sebelum menerima pembayaran.', 'no_shift');
        }

        $hasDiscount = (float) ($data['discount_value'] ?? 0) > 0
            || collect($data['items'])->contains(fn (array $item) => (int) ($item['discount'] ?? 0) > 0);

        if ($hasDiscount && ! $cashier->can('pos.discount')) {
            throw new PosException('Akun Anda tidak punya izin memberi diskon. Hapus diskon atau minta bantuan admin.', 'forbidden_discount');
        }

        $customer = isset($data['customer_id']) ? Customer::query()->whereKey($data['customer_id'])->first() : null;

        try {
            return DB::transaction(function () use ($cashier, $shift, $customer, $data) {
                $this->lockOpenShift($shift->id);

                return $this->storeSale($cashier, $shift->id, $customer, $data);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Dua request dengan client_uuid sama lolos cek di atas bersamaan; yang kalah memakai hasil yang menang.
            $existing = Sale::query()->where('client_uuid', $data['client_uuid'])->first();

            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeSale(User $cashier, int $shiftId, ?Customer $customer, array $data): Sale
    {
        $productIds = collect($data['items'])->pluck('product_id')->unique()->values();
        $products = Product::query()->whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');

        $priceChanges = [];
        $unavailable = [];
        foreach ($data['items'] as $item) {
            $product = $products->get($item['product_id']);

            if (! $product || ! $product->is_active) {
                $unavailable[] = (int) $item['product_id'];

                continue;
            }

            if ((int) $item['price'] !== $product->price) {
                $priceChanges[$product->id] = ['price' => $product->price, 'name' => $product->name];
            }
        }

        if ($unavailable !== []) {
            throw new PosException('Ada produk yang sudah dihapus atau dinonaktifkan. Produk tersebut dikeluarkan dari keranjang.', 'unavailable', ['product_ids' => array_values(array_unique($unavailable))]);
        }

        if ($priceChanges !== []) {
            $names = collect($priceChanges)->pluck('name')->take(3)->implode(', ');

            throw new PosException("Harga {$names} sudah berubah. Keranjang diperbarui dengan harga terbaru, periksa lalu bayar lagi.", 'price_changed', ['prices' => $priceChanges]);
        }

        if (! PosSettings::allowNegativeStock()) {
            $needed = collect($data['items'])->groupBy('product_id')->map(fn ($lines) => $lines->sum(fn ($line) => (float) $line['quantity']));

            foreach ($needed as $productId => $quantity) {
                $product = $products->get($productId);

                if ($product->track_stock && round((float) $product->stock - $quantity, 3) < 0) {
                    $left = NumberFormatter::quantity(max(0, (float) $product->stock));

                    throw new PosException("Stok {$product->name} tidak cukup (tersisa {$left} {$product->unit}).", 'insufficient_stock', ['stock' => [$product->id => (float) $product->stock]]);
                }
            }
        }

        $taxRate = PosSettings::taxRate();
        $lines = collect($data['items'])->map(fn (array $item) => [
            'price' => $products->get($item['product_id'])->price,
            'quantity' => (float) $item['quantity'],
            'discount' => (int) ($item['discount'] ?? 0),
        ])->all();

        $totals = CartCalculator::calculate($lines, $data['discount_type'] ?? null, (float) ($data['discount_value'] ?? 0), $taxRate);

        if (isset($data['expected_total']) && (int) $data['expected_total'] !== $totals['total']) {
            throw new PosException('Total di layar berbeda dengan perhitungan sistem ('.NumberFormatter::currency($totals['total']).'). Muat ulang halaman kasir lalu coba lagi.', 'total_mismatch');
        }

        $payments = $this->resolvePayments($data['payments'] ?? [], $totals['total']);

        if ($payments['due'] > 0) {
            if (! PosSettings::allowCredit()) {
                throw new PosException('Pembayaran kurang '.NumberFormatter::currency($payments['due']).'.', 'underpaid');
            }

            if (! $customer) {
                throw new PosException('Pembayaran kurang '.NumberFormatter::currency($payments['due']).'. Pilih pelanggan dulu kalau sisanya dicatat sebagai kasbon.', 'credit_needs_customer');
            }
        }

        $now = now();
        $sale = Sale::create([
            'number' => $this->numbers->next('TRX', 6),
            'client_uuid' => $data['client_uuid'],
            'cash_shift_id' => $shiftId,
            'user_id' => $cashier->id,
            'customer_id' => $customer?->id,
            'status' => SaleStatus::Completed,
            'subtotal' => $totals['subtotal'],
            'discount_type' => $totals['discount_amount'] > 0 ? $data['discount_type'] : null,
            'discount_value' => $totals['discount_amount'] > 0 ? (float) $data['discount_value'] : 0,
            'discount_amount' => $totals['discount_amount'],
            'tax_rate' => $taxRate,
            'tax_amount' => $totals['tax_amount'],
            'total' => $totals['total'],
            'paid_amount' => $payments['paid'],
            'cash_received' => $payments['cash_received'],
            'change_amount' => $payments['change'],
            'due_amount' => $payments['due'],
            'note' => $data['note'] ?? null,
            'sold_at' => $now,
        ]);

        foreach ($data['items'] as $index => $item) {
            $product = $products->get($item['product_id']);
            $line = $totals['lines'][$index];
            $quantity = (float) $item['quantity'];

            $sale->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'unit' => $product->unit,
                'quantity' => $quantity,
                'price' => $product->price,
                'cost_price' => $product->cost_price,
                'discount_amount' => $line['discount'],
                'total' => $line['total'],
                'note' => $item['note'] ?? null,
            ]);

            if ($product->track_stock) {
                $this->stock->move($product, StockMovementType::Sale, -$quantity, $cashier, $sale, $sale->number);
            }
        }

        foreach ($payments['rows'] as $payment) {
            $sale->payments()->create([
                'cash_shift_id' => $shiftId,
                'user_id' => $cashier->id,
                'kind' => SalePayment::KIND_SALE,
                'method' => $payment['method'],
                'amount' => $payment['amount'],
                'reference' => $payment['reference'],
                'paid_at' => $now,
            ]);
        }

        return $sale;
    }

    /**
     * Uang tunai boleh lebih dari tagihan (selisihnya kembalian); non-tunai tidak boleh melebihi total
     * karena tidak ada kembalian untuk QRIS/transfer.
     *
     * @param  list<array{method: string, amount: int|string, reference?: ?string}>  $payments
     * @return array{rows: list<array{method: PaymentMethod, amount: int, reference: ?string}>, paid: int, cash_received: int, change: int, due: int}
     */
    private function resolvePayments(array $payments, int $total): array
    {
        $enabled = PosSettings::paymentMethods();
        $cash = 0;
        $nonCash = [];

        foreach ($payments as $payment) {
            $method = PaymentMethod::from($payment['method']);
            $amount = (int) $payment['amount'];

            if ($amount <= 0) {
                continue;
            }

            if (! in_array($method, $enabled, true)) {
                throw new PosException("Metode {$method->label()} sedang tidak aktif.");
            }

            if ($method === PaymentMethod::Cash) {
                $cash += $amount;
            } else {
                $nonCash[] = ['method' => $method, 'amount' => $amount, 'reference' => filled($payment['reference'] ?? null) ? (string) $payment['reference'] : null];
            }
        }

        $nonCashTotal = array_sum(array_column($nonCash, 'amount'));

        if ($nonCashTotal > $total) {
            throw new PosException('Pembayaran non-tunai melebihi total belanja. Non-tunai tidak punya kembalian.', 'overpaid_non_cash');
        }

        $cashNeeded = $total - $nonCashTotal;
        $cashApplied = min($cash, $cashNeeded);
        $rows = $nonCash;

        if ($cashApplied > 0) {
            array_unshift($rows, ['method' => PaymentMethod::Cash, 'amount' => $cashApplied, 'reference' => null]);
        }

        return [
            'rows' => $rows,
            'paid' => $nonCashTotal + $cashApplied,
            'cash_received' => $cash,
            'change' => max(0, $cash - $cashNeeded),
            'due' => $cashNeeded - $cashApplied,
        ];
    }

    /**
     * Shared with the API CheckoutRequest so both entry points accept exactly the same cart.
     *
     * @return array<string, mixed>
     */
    public static function checkoutRules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'],
            'customer_id' => ['nullable', 'integer', TenantRule::exists('customers', 'id')->whereNull('deleted_at')],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'items.*.price' => ['required', 'integer', 'min:0'],
            'items.*.discount' => ['nullable', 'integer', 'min:0'],
            'items.*.note' => ['nullable', 'string', 'max:150'],
            'discount_type' => ['nullable', Rule::in(['percent', 'amount'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'payments' => ['present', 'array', 'max:6'],
            'payments.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'payments.*.amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'expected_total' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function checkoutMessages(): array
    {
        return [
            'items.required' => 'Keranjang masih kosong.',
            'items.min' => 'Keranjang masih kosong.',
            'items.*.quantity.gt' => 'Jumlah barang harus lebih dari 0.',
            'customer_id.exists' => 'Pelanggan yang dipilih sudah dihapus.',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateCheckout(array $payload): array
    {
        $validator = Validator::make($payload, self::checkoutRules(), self::checkoutMessages());

        if ($validator->fails()) {
            throw new PosException($validator->errors()->first(), 'validation');
        }

        $data = $validator->validated();

        if (($data['discount_type'] ?? null) === 'percent' && (float) ($data['discount_value'] ?? 0) > 100) {
            throw new PosException('Diskon persen maksimal 100%.', 'validation');
        }

        return $data;
    }

    /**
     * Batalkan transaksi: stok dikembalikan dan uangnya keluar dari laci. Dihitung per pembayaran
     * (pelunasan kasbon bisa masuk di shift lain): yang shiftnya masih buka otomatis tidak dihitung
     * rekapnya; yang sudah ditutup dicatat sebagai kas keluar di shift yang sedang dibuka pembatal.
     */
    public function void(Sale $sale, User $user, string $reason): Sale
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['voidReason' => 'Alasan pembatalan wajib diisi.']);
        }

        return DB::transaction(function () use ($sale, $user, $reason) {
            $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($locked->isVoided()) {
                throw new PosException('Transaksi ini sudah dibatalkan.');
            }

            $cashPaid = (int) $locked->payments()->with('shift')->where('method', PaymentMethod::Cash->value)->get()
                ->reject(fn (SalePayment $payment) => $payment->shift?->isOpen())
                ->sum('amount');
            $refundShift = null;

            if ($cashPaid > 0) {
                $refundShift = $user->openShift();

                if (! $refundShift) {
                    throw new PosException('Shift transaksi ini sudah ditutup. Buka shift kasir Anda dulu supaya pengembalian uang tunai tercatat di laci.');
                }

                $this->lockOpenShift($refundShift->id);
            }

            $productIds = $locked->items()->whereNotNull('product_id')->pluck('product_id');
            $products = Product::withTrashed()->whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');

            foreach ($locked->items as $item) {
                $product = $products->get($item->product_id);

                if ($product && $product->track_stock) {
                    $this->stock->move($product, StockMovementType::SaleVoid, (float) $item->quantity, $user, $locked, "Batal {$locked->number}");
                }
            }

            if ($refundShift) {
                CashMovement::create([
                    'cash_shift_id' => $refundShift->id,
                    'user_id' => $user->id,
                    'type' => CashMovementType::Out,
                    'amount' => $cashPaid,
                    'reason' => "Refund {$locked->number}",
                    'reference_type' => $locked->getMorphClass(),
                    'reference_id' => $locked->id,
                ]);
            }

            $locked->update([
                'status' => SaleStatus::Voided,
                'voided_at' => now(),
                'voided_by' => $user->id,
                'void_reason' => trim($reason),
            ]);

            activity()->performedOn($locked)->causedBy($user)->event('voided')
                ->withProperties(['reason' => trim($reason), 'total' => $locked->total])
                ->log("Transaksi {$locked->number} dibatalkan: ".trim($reason));

            return $locked;
        });
    }

    /**
     * Shift bisa ditutup dari perangkat lain tepat sebelum transaksi ini tersimpan; tanpa kunci ini
     * uangnya tercatat di shift yang expected_cash-nya sudah dibekukan.
     */
    private function lockOpenShift(int $shiftId): void
    {
        $shift = CashShift::query()->whereKey($shiftId)->lockForUpdate()->first();

        if (! $shift || ! $shift->isOpen()) {
            throw new PosException('Shift kasir baru saja ditutup. Buka shift lagi lalu ulangi.', 'no_shift');
        }
    }

    /**
     * Pelunasan kasbon. Pembayaran tunai wajib masuk ke shift yang sedang buka supaya laci tetap cocok.
     */
    public function payReceivable(Sale $sale, User $user, int $amount, PaymentMethod $method, ?string $reference = null): SalePayment
    {
        return DB::transaction(function () use ($sale, $user, $amount, $method, $reference) {
            $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($locked->isVoided()) {
                throw new PosException('Transaksi ini sudah dibatalkan.');
            }

            if ($locked->due_amount <= 0) {
                throw new PosException('Transaksi ini sudah lunas.');
            }

            if ($amount <= 0) {
                throw new PosException('Nominal pembayaran harus lebih dari 0.');
            }

            if ($amount > $locked->due_amount) {
                throw new PosException('Nominal melebihi sisa tagihan ('.NumberFormatter::currency($locked->due_amount).').');
            }

            $shift = $user->openShift();

            if ($method === PaymentMethod::Cash && ! $shift) {
                throw new PosException('Buka shift kasir dulu untuk menerima pelunasan tunai.', 'no_shift');
            }

            if ($shift) {
                $this->lockOpenShift($shift->id);
            }

            $payment = $locked->payments()->create([
                'cash_shift_id' => $shift?->id,
                'user_id' => $user->id,
                'kind' => SalePayment::KIND_RECEIVABLE,
                'method' => $method,
                'amount' => $amount,
                'reference' => $reference,
                'paid_at' => now(),
            ]);

            $locked->update([
                'paid_amount' => $locked->paid_amount + $amount,
                'due_amount' => $locked->due_amount - $amount,
            ]);

            return $payment;
        });
    }
}
