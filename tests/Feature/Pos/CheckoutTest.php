<?php

use App\Enums\StockMovementType;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Services\Pos\PosException;
use App\Services\Pos\SaleService;
use App\Services\Pos\ShiftService;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

function openShiftFor(User $user, int $openingCash = 100000): CashShift
{
    return app(ShiftService::class)->open($user, $openingCash);
}

/**
 * @param  list<array<string, mixed>>  $items
 * @param  list<array<string, mixed>>  $payments
 * @return array<string, mixed>
 */
function checkoutPayload(array $items, array $payments, array $extra = []): array
{
    return [
        'client_uuid' => (string) Str::uuid(),
        'items' => $items,
        'payments' => $payments,
        ...$extra,
    ];
}

function line(Product $product, float $quantity = 1, int $discount = 0): array
{
    return ['product_id' => $product->id, 'quantity' => $quantity, 'price' => $product->price, 'discount' => $discount];
}

function rejection(callable $callback): PosException
{
    try {
        $callback();
    } catch (PosException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected the checkout to be rejected.');
}

beforeEach(function () {
    $this->cashier = actingAsRole('kasir');
    $this->product = Product::factory()->create(['price' => 15000, 'cost_price' => 10000, 'stock' => 10]);
});

test('checkout needs an open shift', function () {
    $error = rejection(fn () => app(SaleService::class)->checkout($this->cashier, checkoutPayload([line($this->product)], [['method' => 'cash', 'amount' => 20000]])));

    expect($error->reason)->toBe('no_shift')
        ->and(Sale::count())->toBe(0);
});

test('cash checkout records the sale, change, payment and stock movement', function () {
    $shift = openShiftFor($this->cashier);

    $sale = app(SaleService::class)->checkout($this->cashier, checkoutPayload([line($this->product, 2)], [['method' => 'cash', 'amount' => 50000]]));

    expect($sale->total)->toBe(30000)
        ->and($sale->cash_received)->toBe(50000)
        ->and($sale->change_amount)->toBe(20000)
        ->and($sale->due_amount)->toBe(0)
        ->and($sale->cash_shift_id)->toBe($shift->id)
        ->and($sale->payments()->sole()->amount)->toBe(30000)
        ->and((float) $this->product->fresh()->stock)->toBe(8.0);

    $movement = $this->product->stockMovements()->sole();
    expect($movement->type)->toBe(StockMovementType::Sale)
        ->and((float) $movement->quantity)->toBe(-2.0)
        ->and($shift->summary()['expected'])->toBe(130000);
});

test('the same checkout sent twice is stored once', function () {
    openShiftFor($this->cashier);
    $payload = checkoutPayload([line($this->product)], [['method' => 'cash', 'amount' => 15000]]);

    $first = app(SaleService::class)->checkout($this->cashier, $payload);
    $second = app(SaleService::class)->checkout($this->cashier, $payload);

    expect($second->id)->toBe($first->id)
        ->and(Sale::count())->toBe(1)
        ->and((float) $this->product->fresh()->stock)->toBe(9.0);
});

test('a price change since the item was added is rejected with the new price', function () {
    openShiftFor($this->cashier);
    $payload = checkoutPayload([line($this->product)], [['method' => 'cash', 'amount' => 15000]]);
    $this->product->update(['price' => 17000]);

    $error = rejection(fn () => app(SaleService::class)->checkout($this->cashier, $payload));

    expect($error->reason)->toBe('price_changed')
        ->and($error->context['prices'][$this->product->id]['price'])->toBe(17000)
        ->and(Sale::count())->toBe(0);
});

test('selling more than the stock is blocked unless negative stock is allowed', function () {
    openShiftFor($this->cashier);

    $error = rejection(fn () => app(SaleService::class)->checkout($this->cashier, checkoutPayload([line($this->product, 6), line($this->product, 5)], [['method' => 'cash', 'amount' => 200000]])));
    expect($error->reason)->toBe('insufficient_stock');

    Setting::put('pos.allow_negative_stock', '1');
    app(SaleService::class)->checkout($this->cashier, checkoutPayload([line($this->product, 11)], [['method' => 'cash', 'amount' => 200000]]));

    expect((float) $this->product->fresh()->stock)->toBe(-1.0);
});

test('untracked products sell without touching stock', function () {
    openShiftFor($this->cashier);
    $service = Product::factory()->untracked()->create(['price' => 5000]);

    app(SaleService::class)->checkout($this->cashier, checkoutPayload([line($service, 3)], [['method' => 'cash', 'amount' => 15000]]));

    expect((float) $service->fresh()->stock)->toBe(0.0)
        ->and($service->stockMovements()->count())->toBe(0);
});

test('inactive or deleted products cannot be sold', function () {
    openShiftFor($this->cashier);
    $inactive = Product::factory()->inactive()->create();
    $deleted = Product::factory()->create();
    $deleted->delete();

    $error = rejection(fn () => app(SaleService::class)->checkout($this->cashier, checkoutPayload([line($inactive), line($deleted)], [['method' => 'cash', 'amount' => 999999]])));

    expect($error->reason)->toBe('unavailable')
        ->and($error->context['product_ids'])->toEqualCanonicalizing([$inactive->id, $deleted->id]);
});

test('discounts need the discount permission', function () {
    openShiftFor($this->cashier);

    $error = rejection(fn () => app(SaleService::class)->checkout($this->cashier, checkoutPayload([line($this->product, 1, 1000)], [['method' => 'cash', 'amount' => 15000]])));
    expect($error->reason)->toBe('forbidden_discount');

    $this->cashier->givePermissionTo(Permission::findOrCreate('pos.discount', 'web'));
    $sale = app(SaleService::class)->checkout($this->cashier->fresh(), checkoutPayload(
        [line($this->product, 2, 1000)],
        [['method' => 'cash', 'amount' => 30000]],
        ['discount_type' => 'percent', 'discount_value' => 10, 'expected_total' => 26100],
    ));

    expect($sale->subtotal)->toBe(29000)
        ->and($sale->discount_amount)->toBe(2900)
        ->and($sale->total)->toBe(26100);
});

test('tax is added after discount when enabled', function () {
    Setting::putMany(['pos.tax_enabled' => '1', 'pos.tax_rate' => '11']);
    openShiftFor($this->cashier);

    $sale = app(SaleService::class)->checkout($this->cashier, checkoutPayload([line($this->product, 2)], [['method' => 'qris', 'amount' => 33300]]));

    expect($sale->tax_amount)->toBe(3300)
        ->and($sale->total)->toBe(33300)
        ->and((float) $sale->tax_rate)->toBe(11.0);
});

test('a total that differs from the screen is rejected', function () {
    openShiftFor($this->cashier);

    $error = rejection(fn () => app(SaleService::class)->checkout($this->cashier, checkoutPayload([line($this->product)], [['method' => 'cash', 'amount' => 15000]], ['expected_total' => 14000])));

    expect($error->reason)->toBe('total_mismatch');
});

test('underpayment needs a customer and is recorded as credit', function () {
    openShiftFor($this->cashier);
    $payload = checkoutPayload([line($this->product, 2)], [['method' => 'cash', 'amount' => 10000]]);

    $error = rejection(fn () => app(SaleService::class)->checkout($this->cashier, $payload));
    expect($error->reason)->toBe('credit_needs_customer');

    $customer = Customer::factory()->create();
    $sale = app(SaleService::class)->checkout($this->cashier, [...$payload, 'customer_id' => $customer->id]);

    expect($sale->paid_amount)->toBe(10000)
        ->and($sale->due_amount)->toBe(20000)
        ->and($customer->outstandingBalance())->toBe(20000);
});

test('credit can be switched off', function () {
    Setting::put('pos.allow_credit', '0');
    openShiftFor($this->cashier);
    $customer = Customer::factory()->create();

    $error = rejection(fn () => app(SaleService::class)->checkout($this->cashier, checkoutPayload([line($this->product)], [['method' => 'cash', 'amount' => 5000]], ['customer_id' => $customer->id])));

    expect($error->reason)->toBe('underpaid');
});

test('split payment keeps change on the cash part only', function () {
    $shift = openShiftFor($this->cashier);

    $sale = app(SaleService::class)->checkout($this->cashier, checkoutPayload([line($this->product, 3)], [
        ['method' => 'qris', 'amount' => 20000],
        ['method' => 'cash', 'amount' => 50000],
    ]));

    expect($sale->total)->toBe(45000)
        ->and($sale->change_amount)->toBe(25000)
        ->and($sale->payments()->pluck('amount', 'method')->all())->toBe(['cash' => 25000, 'qris' => 20000])
        ->and($shift->summary()['cash_sales'])->toBe(25000)
        ->and($shift->summary()['non_cash']['qris'])->toBe(20000);
});

test('non-cash payments cannot exceed the total', function () {
    openShiftFor($this->cashier);

    $error = rejection(fn () => app(SaleService::class)->checkout($this->cashier, checkoutPayload([line($this->product)], [['method' => 'transfer', 'amount' => 20000]])));

    expect($error->reason)->toBe('overpaid_non_cash');
});

test('a disabled payment method is rejected', function () {
    Setting::put('pos.payment_methods', '["qris"]');
    openShiftFor($this->cashier);

    rejection(fn () => app(SaleService::class)->checkout($this->cashier, checkoutPayload([line($this->product)], [['method' => 'card', 'amount' => 15000]])));

    expect(Sale::count())->toBe(0);
});

test('the checkout endpoint answers with json for the cashier screen', function () {
    openShiftFor($this->cashier);

    $this->postJson(route('pos.checkout'), checkoutPayload([line($this->product)], [['method' => 'cash', 'amount' => 20000]]))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('sale.change', 5000)
        ->assertJsonStructure(['sale' => ['number', 'receipt_url', 'whatsapp_url']]);

    $this->postJson(route('pos.checkout'), checkoutPayload([], []))
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Keranjang masih kosong.');
});

test('users without cashier access cannot check out', function () {
    actingAsRole('staff');

    $this->postJson(route('pos.checkout'), checkoutPayload([line($this->product)], [['method' => 'cash', 'amount' => 20000]]))->assertForbidden();
    $this->get(route('pos.cashier'))->assertForbidden();
});

test('selling does not flood the audit log with product stock updates', function () {
    openShiftFor($this->cashier);

    app(SaleService::class)->checkout($this->cashier, checkoutPayload([line($this->product, 2)], [['method' => 'cash', 'amount' => 30000]]));

    expect(Activity::query()->whereMorphedTo('subject', $this->product)->where('event', 'updated')->count())->toBe(0);
});

test('credit beyond the customer limit is refused online but kept from the offline queue', function () {
    openShiftFor($this->cashier);
    $customer = Customer::factory()->create(['credit_limit' => 25000]);
    app(SaleService::class)->checkout($this->cashier, checkoutPayload([line($this->product)], [], ['customer_id' => $customer->id]));

    $payload = checkoutPayload([line($this->product)], [], ['customer_id' => $customer->id]);
    $error = rejection(fn () => app(SaleService::class)->checkout($this->cashier, $payload));

    expect($error->reason)->toBe('credit_limit_exceeded')
        ->and($error->context['room'])->toBe(10000);

    $sale = app(SaleService::class)->checkout($this->cashier, [...$payload, 'offline' => true]);
    expect($sale->flags)->toContain('credit_limit_exceeded')
        ->and($customer->outstandingBalance())->toBe(30000);
});
