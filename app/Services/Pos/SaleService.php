<?php

namespace App\Services\Pos;

use App\Enums\CashMovementType;
use App\Enums\CustomerOrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Events\CustomerReceivableRecorded;
use App\Events\SaleVoided;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Modifier;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductComponent;
use App\Models\ProductOutletPrice;
use App\Models\ProductPriceTier;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\SaleItemComponent;
use App\Models\SalePayment;
use App\Models\Scopes\OutletAccessScope;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use App\Support\CurrentOutlet;
use App\Support\DeviceClock;
use App\Support\Features;
use App\Support\NumberFormatter;
use App\Support\PosSettings;
use App\Support\TenantRule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(
        private DocumentNumberGenerator $numbers,
        private StockService $stock,
        private PrescriptionService $prescriptions,
        private ModifierService $modifiers,
        private KitchenTicketService $kitchen,
        private SerialService $serials,
        private StockCountLateSaleReconciler $lateSales,
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

        $this->ensureSameOutlet($shift, $data['outlet_id'] ?? null);

        // Transaksi dari antrean offline sudah terjadi di dunia nyata, jadi tetap diterima walau outletnya kini terkunci.
        if (! ($data['offline'] ?? false)) {
            app(CurrentOutlet::class)->ensureOperational($shift->outlet_id);
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

                // Pajak, metode bayar, dan harga dibaca untuk outlet shift, bukan outlet yang kebetulan aktif.
                return app(CurrentOutlet::class)->run($shift->outlet_id, fn () => $this->storeSale($cashier, $shift, $customer, $data));
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
    private function storeSale(User $cashier, CashShift $shift, ?Customer $customer, array $data): Sale
    {
        $shiftId = $shift->id;
        $outletId = (int) $shift->outlet_id;
        $outlet = Outlet::query()->findOrFail($outletId);
        $offline = (bool) ($data['offline'] ?? false);
        $productIds = collect($data['items'])->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $components = Features::enabled('business.components')
            ? ProductComponent::query()->whereIn('product_id', $productIds)->get()->groupBy('product_id')
            : new Collection;
        $modifiers = Features::enabledAt('business.modifiers', $outletId) ? $this->modifiers->load($data['items']) : new Collection;
        $ingredientIds = $components->flatten(1)->pluck('component_id')
            ->merge($modifiers->filter(fn (Modifier $modifier) => $modifier->usesIngredient())->pluck('product_id'))
            ->map(fn ($id) => (int) $id);

        // Bahan racikan & modifier ikut dikunci dalam satu urutan id supaya dua checkout tidak saling menunggu.
        $lockIds = $productIds->merge($ingredientIds)->unique()->sort()->values();
        $locked = Product::withTrashed()->whereIn('id', $lockIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $products = $locked->reject(fn (Product $product) => $product->trashed() || ! $productIds->contains($product->id));
        $stocks = $this->stock->lockStocks($lockIds, $outletId);
        $prices = ProductOutletPrice::query()->where('outlet_id', $outletId)->whereIn('product_id', $productIds)->pluck('price', 'product_id');
        $tiers = Features::enabledAt('business.tiered-price', $outletId)
            ? ProductPriceTier::query()->whereIn('product_id', $productIds)->orderBy('min_quantity')->get()->groupBy('product_id')
                ->map(fn ($rows) => $rows->map(fn (ProductPriceTier $tier) => ['min' => (float) $tier->min_quantity, 'price' => (int) $tier->price])->values()->all())
            : new Collection;
        $units = ProductUnit::withTrashed()->whereIn('id', collect($data['items'])->pluck('unit_id')->filter()->unique())->get()->keyBy('id');
        $basePriceOf = fn (Product $product): int => (int) ($prices[$product->id] ?? $product->price);
        $unitOf = fn (array $item): ?ProductUnit => isset($item['unit_id']) ? $units->get($item['unit_id']) : null;
        $tierQuantities = collect($data['items'])->filter(fn (array $item) => ! isset($item['unit_id']))->groupBy('product_id')->map(fn ($rows) => (float) $rows->sum('quantity'));
        $priceOf = function (Product $product, ?ProductUnit $unit = null) use ($basePriceOf, $tiers, $tierQuantities): int {
            if ($unit) {
                return $unit->priceFrom($basePriceOf($product));
            }

            return CartCalculator::tierPrice($basePriceOf($product), $tiers->get($product->id, []), (float) ($tierQuantities[$product->id] ?? 0));
        };
        $factorOf = fn (?ProductUnit $unit): float => $unit ? (float) $unit->factor : 1.0;

        $priceChanges = [];
        $unitPriceChanges = [];
        $unavailable = [];
        $unavailableUnits = [];
        foreach ($data['items'] as $item) {
            $product = $products->get($item['product_id']);

            if (! $product || ! $product->is_active) {
                $unavailable[] = (int) $item['product_id'];

                continue;
            }

            if (! $offline && $product->isVariantParent() && Features::enabledAt('business.variants', $outletId)) {
                throw new PosException("Pilih varian {$product->name} dulu (mis. ukuran atau warna).", 'variant_required', ['product_id' => $product->id]);
            }

            $unit = $unitOf($item);

            if (isset($item['unit_id']) && (! $unit || $unit->product_id !== $product->id || ($unit->trashed() && ! $offline))) {
                $unavailableUnits[] = (int) $item['unit_id'];

                continue;
            }

            if ((int) $item['price'] !== $priceOf($product, $unit)) {
                if ($unit) {
                    $unitPriceChanges[$unit->id] = ['price' => $priceOf($product, $unit), 'name' => "{$product->name} ({$unit->name})", 'product_id' => $product->id];
                } else {
                    $priceChanges[$product->id] = ['price' => $priceOf($product), 'base_price' => $basePriceOf($product), 'tiers' => $tiers->get($product->id, []), 'name' => $product->name];
                }
            }
        }

        if ($unavailable !== []) {
            throw new PosException('Ada produk yang sudah dihapus atau dinonaktifkan. Produk tersebut dikeluarkan dari keranjang.', 'unavailable', ['product_ids' => array_values(array_unique($unavailable))]);
        }

        // Produk dari kategori outlet lain, atau yang butuh fitur yang mati di outlet ini. Kode `unavailable`
        // dipakai ulang supaya kasir web & aplikasi lama langsung mengeluarkannya dari keranjang.
        $notSoldHere = $products->keys()->diff(Product::query()->sellableAt($outletId)->whereIn('products.id', $products->keys())->pluck('products.id'))->values();

        if ($notSoldHere->isNotEmpty() && ! $offline) {
            $names = $products->only($notSoldHere->all())->pluck('name')->take(3)->implode(', ');

            throw new PosException("{$names} tidak dijual di outlet ini, jadi dikeluarkan dari keranjang.", 'unavailable', ['product_ids' => $notSoldHere->all()]);
        }

        if ($unavailableUnits !== []) {
            throw new PosException('Ada satuan jual yang sudah dihapus. Ganti satuannya di keranjang lalu bayar lagi.', 'unit_unavailable', ['unit_ids' => array_values(array_unique($unavailableUnits))]);
        }

        if ($priceChanges !== [] || $unitPriceChanges !== []) {
            $names = collect([...array_values($priceChanges), ...array_values($unitPriceChanges)])->pluck('name')->take(3)->implode(', ');

            throw new PosException("Harga {$names} sudah berubah. Keranjang diperbarui dengan harga terbaru, periksa lalu bayar lagi.", 'price_changed', ['prices' => $priceChanges, 'unit_prices' => $unitPriceChanges]);
        }

        $nearExpiry = BatchService::nearExpiryQuantities($productIds, $outletId);
        $nearPercent = PosSettings::nearExpiryDiscountPercent();
        $autoDiscounts = [];
        $nearExpiryChanged = false;
        $nearLeft = $nearExpiry;
        foreach ($data['items'] as $index => $item) {
            $product = $products->get($item['product_id']);
            $expected = 0;

            if (! isset($item['unit_id']) && isset($nearLeft[$product->id])) {
                $quantity = (float) $item['quantity'];
                $expected = CartCalculator::nearExpiryDiscount($priceOf($product), $quantity, $nearLeft[$product->id], $nearPercent);
                $nearLeft[$product->id] = round(max(0, $nearLeft[$product->id] - $quantity), 3);
            }

            $sent = (int) ($item['auto_discount'] ?? 0);
            $nearExpiryChanged = $nearExpiryChanged || $sent !== $expected;
            $autoDiscounts[$index] = $offline ? max(0, $sent) : $expected;
        }

        if ($nearExpiryChanged && ! $offline) {
            throw new PosException('Stok yang hampir kedaluwarsa berubah sehingga potongan ED dekat ikut berubah. Keranjang diperbarui, periksa lalu bayar lagi.', 'near_expiry_changed', [
                'near_expiry' => collect($productIds)->mapWithKeys(fn (int $id) => [$id => ['quantity' => $nearExpiry[$id] ?? 0, 'percent' => $nearPercent]])->all(),
            ]);
        }

        $modifierLines = Features::enabledAt('business.modifiers', $outletId) || collect($data['items'])->contains(fn (array $item) => ! empty($item['modifiers']))
            ? $this->modifiers->resolve($data['items'], $products, $modifiers, $offline)
            : ['lines' => [], 'flags' => []];

        $baseQuantities = collect($data['items'])->map(fn (array $item) => round((float) $item['quantity'] * $factorOf($unitOf($item)), 3))->all();
        $prescription = $this->prescriptions->resolveForSale($cashier, $outletId, $products, $data, $baseQuantities);

        // Kebutuhan stok per produk dalam satuan dasar: barang yang dijual (racikan tanpa stok diganti bahannya) plus bahan modifier.
        $demands = [];
        foreach ($data['items'] as $index => $item) {
            $product = $products->get($item['product_id']);

            foreach ($this->stockDemands($product, $baseQuantities[$index], (float) $item['quantity'], $components, $modifierLines['lines'][$index]['ingredients'] ?? []) as $demand) {
                $demands[$index][] = $demand;
            }
        }

        if (! PosSettings::allowNegativeStock()) {
            $needed = collect($demands)->flatten(1)->groupBy('product_id')->map(fn ($rows) => round((float) $rows->sum('quantity'), 3));

            foreach ($needed as $productId => $quantity) {
                $product = $locked->get($productId);
                $available = (float) ($stocks->get($productId)?->stock ?? 0);

                if ($product && $product->track_stock && round($available - $quantity, 3) < 0) {
                    $left = NumberFormatter::quantity(max(0, $available));

                    throw new PosException("Stok {$product->name} tidak cukup (tersisa {$left} {$product->unit}).", 'insufficient_stock', ['stock' => [$product->id => $available]]);
                }
            }
        }

        $orderType = Features::enabledAt('business.order-type', $outletId) ? OrderType::tryFrom((string) ($data['order_type'] ?? ''))?->value : null;
        $tableLabel = $orderType !== null && filled($data['table_label'] ?? null) ? mb_substr(trim((string) $data['table_label']), 0, 30) : null;
        $taxRate = PosSettings::taxRate();
        $serviceRate = PosSettings::serviceChargeRate($orderType);
        $lines = collect($data['items'])->map(fn (array $item, int $index) => [
            'price' => $priceOf($products->get($item['product_id']), $unitOf($item)),
            'modifiers' => $modifierLines['lines'][$index]['total'] ?? 0,
            'quantity' => (float) $item['quantity'],
            'discount' => (int) ($item['discount'] ?? 0) + $autoDiscounts[$index],
        ])->all();

        $totals = CartCalculator::calculate($lines, $data['discount_type'] ?? null, (float) ($data['discount_value'] ?? 0), $taxRate, $serviceRate);

        if (isset($data['expected_total']) && (int) $data['expected_total'] !== $totals['total']) {
            throw new PosException('Total di layar berbeda dengan perhitungan sistem ('.NumberFormatter::currency($totals['total']).'). Muat ulang halaman kasir lalu coba lagi.', 'total_mismatch');
        }

        $order = isset($data['customer_order_id']) ? $this->lockOrder((int) $data['customer_order_id'], $offline) : null;
        $depositRows = $order ? $order->payments()->get()->groupBy(fn ($payment) => $payment->method->value)->map(fn ($rows) => (int) $rows->sum('amount'))->filter(fn (int $amount) => $amount > 0) : collect();
        $deposit = (int) $depositRows->sum();

        if ($deposit > $totals['total'] && ! $offline) {
            throw new PosException('Uang muka pesanan ('.NumberFormatter::currency($deposit).') melebihi total belanja. Tambahkan barang pesanannya atau kembalikan sebagian DP dari halaman Pesanan.', 'deposit_exceeds_total');
        }

        $deposit = min($deposit, $totals['total']);
        $payments = $this->resolvePayments($data['payments'] ?? [], $totals['total'] - $deposit);
        $payments['paid'] += $deposit;

        if ($payments['due'] > 0) {
            if (! PosSettings::allowCredit()) {
                throw new PosException('Pembayaran kurang '.NumberFormatter::currency($payments['due']).'.', 'underpaid');
            }

            if (! $customer) {
                throw new PosException('Pembayaran kurang '.NumberFormatter::currency($payments['due']).'. Pilih pelanggan dulu kalau sisanya dicatat sebagai kasbon.', 'credit_needs_customer');
            }

            $outstanding = $customer->credit_limit === null ? 0 : $customer->outstandingBalance();
            $creditExceeded = $customer->credit_limit !== null && $outstanding + $payments['due'] > $customer->credit_limit;

            if ($creditExceeded && ! $offline) {
                $room = max(0, $customer->credit_limit - $outstanding);

                throw new PosException("Kasbon {$customer->name} melewati batas ".NumberFormatter::currency($customer->credit_limit).'. Sisa yang boleh dikasbon '.NumberFormatter::currency($room).'.', 'credit_limit_exceeded', ['limit' => $customer->credit_limit, 'outstanding' => $outstanding, 'room' => $room]);
            }
        }

        $now = now();
        $sale = Sale::create([
            'outlet_id' => $outletId,
            'number' => $this->numbers->next('TRX', 6, null, $outlet),
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
            'service_charge_rate' => $serviceRate,
            'service_charge_amount' => $totals['service_charge_amount'],
            'total' => $totals['total'],
            'paid_amount' => $payments['paid'],
            'cash_received' => $payments['cash_received'],
            'change_amount' => $payments['change'],
            'due_amount' => $payments['due'],
            'note' => $data['note'] ?? null,
            'sold_at' => $now,
            'prescription_id' => $prescription['prescription']?->id,
            'order_type' => $orderType,
            'table_label' => $tableLabel,
            'queue_number' => $orderType !== null ? $this->nextQueueNumber($outletId) : null,
            'customer_order_id' => $order?->id,
        ]);

        $flags = [...$prescription['flags'], ...$modifierLines['flags'], ...(($creditExceeded ?? false) ? ['credit_limit_exceeded'] : []), ...($notSoldHere->isNotEmpty() ? ['not_sold_at_outlet'] : [])];

        if ($offline && ! $outlet->isOperational()) {
            $flags[] = 'outlet_locked_sync';
        }
        $allowExpired = $offline || ! PosSettings::blockExpiredSale();
        $occurredAt = $offline ? DeviceClock::correct($data['occurred_at'] ?? null, $data['device_sent_at'] ?? null, $shift->opened_at) : null;
        $kitchenItems = [];

        foreach ($data['items'] as $index => $item) {
            $product = $products->get($item['product_id']);
            $unit = $unitOf($item);
            $line = $totals['lines'][$index];
            $quantity = (float) $item['quantity'];
            $baseQuantity = $baseQuantities[$index];
            $modifierLine = $modifierLines['lines'][$index] ?? ['total' => 0, 'snapshot' => []];

            $saleItem = $sale->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'unit' => $unit?->name ?? $product->unit,
                'quantity' => $quantity,
                'price' => $priceOf($product, $unit),
                'cost_price' => (int) round($product->cost_price * $factorOf($unit)),
                'discount_amount' => $line['discount'],
                'total' => $line['total'],
                'note' => $item['note'] ?? null,
                'product_unit_id' => $unit?->id,
                'unit_factor' => $factorOf($unit),
                'base_quantity' => $baseQuantity,
                'prescription_item_id' => $prescription['items'][$product->id] ?? null,
                'modifiers' => $modifierLine['snapshot'] !== [] ? $modifierLine['snapshot'] : null,
                'modifiers_total' => $modifierLine['total'],
                'auto_discount' => min($autoDiscounts[$index], $line['discount']),
            ]);

            $kitchenItems[] = [...$item, 'name' => $product->name, 'unit' => $saleItem->unit, 'modifiers' => $modifierLine['snapshot']];

            foreach ($demands[$index] ?? [] as $demand) {
                $target = $locked->get($demand['product_id']);

                if (! $target || ! $target->track_stock) {
                    continue;
                }

                $movement = $this->stock->move($target, StockMovementType::Sale, -$demand['quantity'], $cashier, $sale, $sale->number, null, $outletId, [
                    'allow_expired' => $allowExpired,
                    'batch_id' => $demand['source'] === null && isset($item['batch_id']) ? (int) $item['batch_id'] : null,
                ], $occurredAt);

                if ($demand['source'] === null) {
                    foreach ($movement->batchLines as $batchLine) {
                        $saleItem->batches()->create(['product_batch_id' => $batchLine->product_batch_id, 'quantity' => abs((float) $batchLine->quantity)]);
                    }

                    if ($target->tracksSerials() && ! $this->serials->sell($target, $outletId, (array) ($item['serials'] ?? []), $demand['quantity'], $saleItem, $offline)) {
                        $flags[] = 'serial_unverified';
                    }
                } else {
                    $saleItem->components()->create(['product_id' => $target->id, 'stock_movement_id' => $movement->id, 'quantity' => $demand['quantity'], 'source' => $demand['source']]);
                }

                if ($occurredAt !== null) {
                    $this->lateSales->reconcile($sale, $saleItem, $target, $movement, $cashier, $demand['source'] === null ? (array) ($item['serials'] ?? []) : []);
                }

                if ($offline && $movement->batchLines->isNotEmpty() && ProductBatch::query()->whereIn('id', $movement->batchLines->pluck('product_batch_id'))->whereDate('expires_at', '<', today())->exists()) {
                    $flags[] = 'expired_batch_sold';
                }
            }
        }

        if ($prescription['prescription']) {
            $this->prescriptions->dispense($prescription['prescription'], $sale);
        }

        if ($orderType !== null) {
            $this->kitchen->send($kitchenItems, (array) ($data['kitchen_sent'] ?? []), KitchenTicketService::label($tableLabel, $sale->orderLabel()), $orderType, $cashier, $sale, $outletId);
        }

        if ($flags !== []) {
            $sale->forceFill(['flags' => array_values(array_unique($flags))])->saveQuietly();
            Log::warning('Transaksi offline ditandai untuk ditinjau.', ['sale' => $sale->number, 'flags' => $sale->flags]);
        }

        $left = $deposit;
        foreach ($depositRows as $method => $amount) {
            $applied = min($amount, $left);
            $left -= $applied;

            if ($applied > 0) {
                $sale->payments()->create([
                    'outlet_id' => $outletId,
                    'cash_shift_id' => null,
                    'user_id' => $cashier->id,
                    'kind' => SalePayment::KIND_DEPOSIT,
                    'method' => $method,
                    'amount' => $applied,
                    'reference' => $order->number,
                    'paid_at' => $now,
                ]);
            }
        }

        $order?->update(['status' => CustomerOrderStatus::PickedUp, 'sale_id' => $sale->id, 'completed_at' => $now]);

        foreach ($payments['rows'] as $payment) {
            $sale->payments()->create([
                'outlet_id' => $outletId,
                'cash_shift_id' => $shiftId,
                'user_id' => $cashier->id,
                'kind' => SalePayment::KIND_SALE,
                'method' => $payment['method'],
                'amount' => $payment['amount'],
                'reference' => $payment['reference'],
                'paid_at' => $now,
            ]);
        }

        if ($sale->due_amount > 0) {
            CustomerReceivableRecorded::dispatch($sale, (int) $sale->due_amount, 'created', $cashier);
        }

        return $sale;
    }

    /**
     * Pesanan yang dilunasi lewat checkout ini. Transaksi offline atas pesanan yang sudah selesai/dibatalkan
     * tetap dicatat, hanya tanpa tautan pesanan.
     *
     * @throws PosException
     */
    private function lockOrder(int $orderId, bool $offline): ?CustomerOrder
    {
        $order = CustomerOrder::query()->withoutGlobalScope(OutletAccessScope::class)->whereKey($orderId)->lockForUpdate()->first();

        if ($order && $order->isOpen()) {
            return $order;
        }

        if ($offline) {
            return null;
        }

        throw new PosException('Pesanan ini sudah selesai atau dibatalkan. Lepaskan pesanan dari keranjang.', 'order_closed');
    }

    /**
     * Potongan stok satu baris dalam satuan dasar. Produk racikan yang stoknya tidak dilacak memotong bahannya;
     * source null berarti barang itu sendiri (dicatat per batch di sale_item_batches).
     *
     * @param  Collection<int, Collection<int, ProductComponent>>  $components
     * @param  list<array{product_id: int, quantity: float}>  $ingredients
     * @return list<array{product_id: int, quantity: float, source: ?string}>
     */
    private function stockDemands(Product $product, float $baseQuantity, float $quantity, Collection $components, array $ingredients): array
    {
        $demands = [];

        if ($product->track_stock) {
            $demands[] = ['product_id' => $product->id, 'quantity' => $baseQuantity, 'source' => null];
        } else {
            foreach ($components->get($product->id, []) as $component) {
                $demands[] = ['product_id' => (int) $component->component_id, 'quantity' => round((float) $component->quantity * $baseQuantity, 3), 'source' => SaleItemComponent::SOURCE_RECIPE];
            }
        }

        foreach ($ingredients as $ingredient) {
            $demands[] = ['product_id' => $ingredient['product_id'], 'quantity' => round($ingredient['quantity'] * $quantity, 3), 'source' => SaleItemComponent::SOURCE_MODIFIER];
        }

        return array_values(array_filter($demands, fn (array $demand) => $demand['quantity'] > 0));
    }

    /**
     * Nomor antrean harian per outlet. Baris outlet dikunci supaya dua kasir tidak mendapat nomor yang sama.
     */
    private function nextQueueNumber(int $outletId): int
    {
        Outlet::query()->whereKey($outletId)->lockForUpdate()->first();

        return (int) Sale::query()->where('outlet_id', $outletId)->whereDate('sold_at', today())->max('queue_number') + 1;
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
            'outlet_id' => ['nullable', 'integer'],
            'offline' => ['nullable', 'boolean'],
            'occurred_at' => ['nullable', 'date'],
            'device_sent_at' => ['nullable', 'date'],
            'customer_id' => ['nullable', 'integer', TenantRule::exists('customers', 'id')->whereNull('deleted_at')],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'items.*.price' => ['required', 'integer', 'min:0'],
            'items.*.discount' => ['nullable', 'integer', 'min:0'],
            'items.*.note' => ['nullable', 'string', 'max:150'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.batch_id' => ['nullable', 'integer'],
            'items.*.modifiers' => ['nullable', 'array', 'max:20'],
            'items.*.modifiers.*.id' => ['required', 'integer'],
            'items.*.modifiers.*.price' => ['required', 'integer', 'min:0', 'max:999999999'],
            'items.*.modifiers.*.name' => ['nullable', 'string', 'max:60'],
            'order_type' => ['nullable', Rule::enum(OrderType::class)],
            'table_label' => ['nullable', 'string', 'max:30'],
            'kitchen_sent' => ['nullable', 'array', 'max:300'],
            'kitchen_sent.*' => ['numeric', 'min:0'],
            'items.*.auto_discount' => ['nullable', 'integer', 'min:0'],
            'items.*.serials' => ['nullable', 'array', 'max:500'],
            'items.*.serials.*' => ['string', 'max:64'],
            'customer_order_id' => ['nullable', 'integer'],
            'prescription_id' => ['nullable', 'integer'],
            'prescription' => ['nullable', 'array'],
            'prescription.doctor_name' => ['required_with:prescription', 'string', 'max:100'],
            'prescription.doctor_sip' => ['nullable', 'string', 'max:50'],
            'prescription.clinic_name' => ['nullable', 'string', 'max:150'],
            'prescription.patient_name' => ['required_with:prescription', 'string', 'max:100'],
            'prescription.patient_age' => ['nullable', 'integer', 'min:0', 'max:150'],
            'prescription.patient_phone' => ['nullable', 'string', 'max:30'],
            'prescription.prescription_date' => ['nullable', 'date'],
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

            $items = $locked->items()->with(['batches', 'components.movement.batchLines'])->get();
            $productIds = $items->pluck('product_id')->filter()->merge($items->flatMap->components->pluck('product_id'))->unique();
            $products = Product::withTrashed()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($items as $item) {
                $product = $products->get($item->product_id);

                // Racikan memotong bahannya, bukan dirinya sendiri; yang dikembalikan bahannya di bawah.
                if ($product && $product->track_stock && $item->components->where('source', SaleItemComponent::SOURCE_RECIPE)->isEmpty()) {
                    $this->serials->restore($item, (int) $locked->outlet_id);
                    $restore = $item->batches->mapWithKeys(fn ($line) => [$line->product_batch_id => (float) $line->quantity])->all();
                    $this->stock->move($product, StockMovementType::SaleVoid, $item->baseQuantity(), $user, $locked, "Batal {$locked->number}", null, (int) $locked->outlet_id, ['restore' => $restore]);
                }

                foreach ($item->components as $component) {
                    $target = $products->get($component->product_id);

                    if ($target && $target->track_stock) {
                        $restore = $component->movement?->batchLines->mapWithKeys(fn ($line) => [$line->product_batch_id => abs((float) $line->quantity)])->all() ?? [];
                        $this->stock->move($target, StockMovementType::SaleVoid, (float) $component->quantity, $user, $locked, "Batal {$locked->number}", null, (int) $locked->outlet_id, ['restore' => $restore]);
                    }
                }
            }

            if ($locked->prescription_id) {
                $this->prescriptions->undispense($locked);
            }

            // Pesanan dibuka lagi supaya DP-nya bisa dipakai di pelunasan berikutnya.
            if ($locked->customer_order_id) {
                CustomerOrder::query()->withoutGlobalScope(OutletAccessScope::class)->whereKey($locked->customer_order_id)->where('sale_id', $locked->id)
                    ->first()?->update(['status' => CustomerOrderStatus::Ready, 'sale_id' => null, 'completed_at' => null]);
            }

            if ($refundShift) {
                CashMovement::create([
                    'outlet_id' => $refundShift->outlet_id,
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

            SaleVoided::dispatch($locked, $user, trim($reason));

            return $locked;
        });
    }

    /**
     * Shift terbuka milik kasir harus di outlet yang sedang dipakai, dan outlet di payload (dari
     * antrean offline) harus sama dengan outlet shift; selain itu uang dan stok masuk ke outlet yang salah.
     *
     * @throws PosException
     */
    private function ensureSameOutlet(CashShift $shift, mixed $payloadOutletId = null): void
    {
        $contextId = app(CurrentOutlet::class)->id();
        $shiftOutletId = (int) $shift->outlet_id;

        if (($payloadOutletId !== null && (int) $payloadOutletId !== $shiftOutletId) || ($contextId !== null && $contextId !== $shiftOutletId)) {
            $name = Outlet::query()->whereKey($shiftOutletId)->value('name');

            throw new PosException("Shift Anda terbuka di outlet {$name}, bukan di outlet ini. Pindah ke outlet {$name} atau tutup shift itu dulu.", 'outlet_mismatch');
        }
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
                $this->ensureSameOutlet($shift);
                $this->lockOpenShift($shift->id);
            }

            // Uang diterima di outlet penerima (shift kasir), bukan outlet tempat transaksi dibuat.
            $payment = $locked->payments()->create([
                'outlet_id' => $shift?->outlet_id ?? app(CurrentOutlet::class)->idOrPrimary(),
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

            CustomerReceivableRecorded::dispatch($locked, $amount, 'collected', $user);

            return $payment;
        });
    }
}
