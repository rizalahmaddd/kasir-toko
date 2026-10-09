<?php

use App\Enums\CashMovementType;
use App\Enums\CustomerOrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\StockMovementType;
use App\Livewire\Inventory\SerialNumbers;
use App\Livewire\MasterData\Products;
use App\Livewire\Orders\CustomerOrders;
use App\Livewire\Orders\CustomerOrderShow;
use App\Livewire\Sales\SaleShow;
use App\Models\CashMovement;
use App\Models\CustomerOrder;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\User;
use App\Services\Pos\CustomerOrderService;
use App\Services\Pos\PosException;
use App\Services\Pos\SaleService;
use App\Services\Pos\ShiftService;
use App\Services\Pos\StockService;
use App\Services\Pos\StockTransferService;
use App\Support\Features;
use Illuminate\Support\Str;
use Livewire\Livewire;

function specialtySale(User $cashier, array $items, array $extra = [], array $payments = [['method' => 'cash', 'amount' => 10_000_000]])
{
    return app(SaleService::class)->checkout($cashier, [
        'client_uuid' => (string) Str::uuid(),
        'items' => $items,
        'payments' => $payments,
        ...$extra,
    ]);
}

function specialtyRejection(callable $callback): PosException
{
    try {
        $callback();
    } catch (PosException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected a rejection.');
}

beforeEach(function () {
    Features::setEnabled(['business.variants', 'business.serial-number', 'business.pre-order', 'business.delivery-note']);
    $this->cashier = actingAsRole('admin');
    $this->shift = app(ShiftService::class)->open($this->cashier, 0);
});

test('variant options become child SKUs and the parent cannot be sold directly', function () {
    $shirt = Product::factory()->create(['name' => 'Kaos Polos', 'price' => 65000, 'stock' => 0]);

    Livewire::test(Products::class)
        ->call('openEditModal', $shirt->id)
        ->call('addVariantOption')
        ->set('variant_options.0.name', 'Ukuran')
        ->set('variant_options.0.values', 'S, M')
        ->call('addVariantOption')
        ->set('variant_options.1.name', 'Warna')
        ->set('variant_options.1.values', 'Hitam, Putih')
        ->call('save')
        ->assertHasNoErrors();

    $children = $shirt->fresh()->variants;
    expect($children)->toHaveCount(4)
        ->and($children->pluck('name')->all())->toContain('Kaos Polos - M / Hitam')
        ->and($shirt->fresh()->track_stock)->toBeFalse();

    expect(specialtyRejection(fn () => specialtySale($this->cashier, [['product_id' => $shirt->id, 'quantity' => 1, 'price' => 65000]]))->reason)->toBe('variant_required');

    $child = $children->firstWhere('name', 'Kaos Polos - M / Hitam');
    app(StockService::class)->adjust($child, StockMovementType::StockIn, 5, $this->cashier);
    $sale = specialtySale($this->cashier, [['product_id' => $child->id, 'quantity' => 2, 'price' => 65000]]);
    expect($sale->total)->toBe(130000)->and((float) $child->fresh()->stock)->toBe(3.0);

    Livewire::test(Products::class)
        ->call('openEditModal', $shirt->id)
        ->set('variant_options.1.values', 'Hitam')
        ->call('save')
        ->assertHasNoErrors();

    expect($shirt->fresh()->variants)->toHaveCount(2);
});

test('serial products need serials to come in, go out, and be sold, and a void puts the unit back', function () {
    $phone = Product::factory()->create(['name' => 'HP Android', 'price' => 2_000_000, 'stock' => 0, 'track_serial' => true, 'warranty_days' => 365]);
    $stock = app(StockService::class);

    expect(fn () => $stock->adjust($phone, StockMovementType::StockIn, 2, $this->cashier, batch: ['serials' => ['IMEI-1']]))->toThrow(PosException::class, 'Isi 2 nomor seri');
    $stock->adjust($phone, StockMovementType::StockIn, 2, $this->cashier, batch: ['serials' => ['imei-1', 'IMEI-2']]);
    expect(fn () => $stock->adjust($phone, StockMovementType::StockIn, 1, $this->cashier, batch: ['serials' => ['IMEI-2']]))->toThrow(PosException::class, 'sudah tercatat');
    expect(fn () => $stock->adjust($phone, StockMovementType::Opname, 1, $this->cashier))->toThrow(PosException::class, 'nomor seri');

    $line = ['product_id' => $phone->id, 'quantity' => 1, 'price' => 2_000_000];
    expect(specialtyRejection(fn () => specialtySale($this->cashier, [$line]))->reason)->toBe('serial_required')
        ->and(specialtyRejection(fn () => specialtySale($this->cashier, [[...$line, 'serials' => ['IMEI-9']]]))->reason)->toBe('serial_unavailable');

    $sale = specialtySale($this->cashier, [[...$line, 'serials' => ['IMEI-1']]]);
    $unit = ProductSerial::query()->where('serial', 'IMEI-1')->sole();
    expect($unit->status)->toBe(ProductSerial::SOLD)
        ->and($unit->sale_item_id)->toBe($sale->items->sole()->id);

    $this->get(route('pos.receipt', $sale))->assertOk()->assertSee('SN: IMEI-1')->assertSee('Garansi s/d');

    app(SaleService::class)->void($sale, $this->cashier, 'Retur');
    expect($unit->fresh()->status)->toBe(ProductSerial::IN_STOCK)
        ->and((float) $phone->fresh()->stock)->toBe(2.0);
});

test('an offline sale with an unknown serial is kept and flagged', function () {
    $phone = Product::factory()->create(['name' => 'HP Lama', 'price' => 900_000, 'stock' => 0, 'track_serial' => true]);
    app(StockService::class)->adjust($phone, StockMovementType::StockIn, 1, $this->cashier, batch: ['serials' => ['SN-A']]);

    $sale = specialtySale($this->cashier, [['product_id' => $phone->id, 'quantity' => 1, 'price' => 900_000, 'serials' => ['SN-X']]], ['offline' => true]);

    expect($sale->flags)->toContain('serial_unverified')
        ->and(ProductSerial::query()->where('serial', 'SN-X')->value('status'))->toBe(ProductSerial::SOLD);
});

test('serial units follow a stock transfer', function () {
    $phone = Product::factory()->create(['name' => 'HP Transfer', 'stock' => 0, 'track_serial' => true]);
    app(StockService::class)->adjust($phone, StockMovementType::StockIn, 2, $this->cashier, batch: ['serials' => ['T-1', 'T-2']]);
    $branch = makeOutlet(['name' => 'Cabang']);

    app(StockTransferService::class)->create($this->cashier, primaryOutlet()->id, $branch->id, [['product_id' => $phone->id, 'quantity' => 1]]);

    expect(ProductSerial::query()->where('serial', 'T-1')->value('outlet_id'))->toBe($branch->id)
        ->and(ProductSerial::query()->where('serial', 'T-2')->value('outlet_id'))->toBe(primaryOutlet()->id);
});

test('existing stock can be given serials on the serial page without changing stock', function () {
    $phone = Product::factory()->create(['name' => 'HP Stok Lama', 'stock' => 0]);
    app(StockService::class)->adjust($phone, StockMovementType::StockIn, 2, $this->cashier);
    $phone->update(['track_serial' => true]);

    Livewire::test(SerialNumbers::class)
        ->assertSee('HP Stok Lama')
        ->set('productId', (string) $phone->id)
        ->set('serialText', "A1\nA2\nA3")
        ->call('register')
        ->assertHasErrors(['serialText'])
        ->set('serialText', "A1\nA2")
        ->call('register')
        ->assertHasNoErrors();

    expect(ProductSerial::query()->where('product_id', $phone->id)->count())->toBe(2)
        ->and((float) $phone->fresh()->stock)->toBe(2.0);
});

test('an order deposit goes into the drawer and is deducted when the order is settled at the cashier', function () {
    $cake = Product::factory()->create(['name' => 'Kue Ulang Tahun', 'price' => 250_000, 'track_stock' => false]);

    $order = app(CustomerOrderService::class)->create($this->cashier, [
        'customer_name' => 'Bu Rina',
        'pickup_at' => now()->addDays(2)->toDateTimeString(),
        'items' => [['product_id' => $cake->id, 'quantity' => 1, 'note' => 'Tulisan: Selamat Ulang Tahun']],
        'deposit' => 100_000,
        'deposit_method' => 'cash',
    ]);

    expect($order->deposit)->toBe(100_000)
        ->and($order->remaining())->toBe(150_000)
        ->and(CashMovement::query()->where('reason', "DP {$order->number}")->value('amount'))->toBe(100_000)
        ->and($this->shift->fresh()->summary()['expected'])->toBe(100_000);

    $sale = specialtySale($this->cashier, [['product_id' => $cake->id, 'quantity' => 1, 'price' => 250_000]], ['customer_order_id' => $order->id], [['method' => 'cash', 'amount' => 150_000]]);

    expect($sale->paid_amount)->toBe(250_000)
        ->and($sale->due_amount)->toBe(0)
        ->and($sale->payments->firstWhere('kind', 'deposit')->amount)->toBe(100_000)
        ->and($order->fresh()->status)->toBe(CustomerOrderStatus::PickedUp)
        ->and($this->shift->fresh()->summary()['expected'])->toBe(250_000);

    app(SaleService::class)->void($sale, $this->cashier, 'Salah kue');
    expect($order->fresh()->status)->toBe(CustomerOrderStatus::Ready)
        ->and($order->fresh()->sale_id)->toBeNull();
});

test('cancelling an order can refund the cash deposit from the drawer', function () {
    $order = app(CustomerOrderService::class)->create($this->cashier, ['customer_name' => 'Pak Andi', 'type' => 'service', 'device' => 'Samsung A54', 'complaint' => 'LCD pecah', 'deposit' => 50_000]);

    Livewire::test(CustomerOrderShow::class, ['order' => $order])
        ->assertSee('Samsung A54')
        ->set('cancelReason', 'Pelanggan batal')
        ->set('refund', true)
        ->call('cancel')
        ->assertHasNoErrors();

    expect($order->fresh()->status)->toBe(CustomerOrderStatus::Cancelled)
        ->and($order->fresh()->deposit)->toBe(0)
        ->and(CashMovement::query()->where('type', CashMovementType::Out)->value('amount'))->toBe(50_000);

    expect(specialtyRejection(fn () => specialtySale($this->cashier, [['product_id' => Product::factory()->create(['price' => 1000, 'track_stock' => false])->id, 'quantity' => 1, 'price' => 1000]], ['customer_order_id' => $order->id]))->reason)->toBe('order_closed');
});

test('the orders page creates an order with items and a non-cash deposit', function () {
    $cake = Product::factory()->create(['name' => 'Bolu Pandan', 'price' => 80_000, 'track_stock' => false]);

    Livewire::test(CustomerOrders::class)
        ->call('openCreate', 'order')
        ->set('form.customer_name', 'Bu Sari')
        ->set('productSearch', 'Bolu')
        ->call('addProduct', $cake->id)
        ->set('form.deposit', '30.000')
        ->set('form.deposit_method', PaymentMethod::Qris->value)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $order = CustomerOrder::query()->where('customer_name', 'Bu Sari')->sole();
    expect($order->estimated_total)->toBe(80_000)
        ->and($order->deposit)->toBe(30_000)
        ->and(CashMovement::query()->count())->toBe(0);

    $this->get(route('orders.print', $order))->assertOk()->assertSee($order->number);
});

test('a delivery note is created from the sale page and printed without prices', function () {
    $cement = Product::factory()->create(['name' => 'Semen 50kg', 'price' => 65000, 'track_stock' => false]);
    $sale = specialtySale($this->cashier, [['product_id' => $cement->id, 'quantity' => 10, 'price' => 65000]]);

    Livewire::test(SaleShow::class, ['sale' => $sale])
        ->call('openDelivery')
        ->set('delivery.recipient', 'Pak Budi')
        ->set('delivery.address', 'Jl. Melati 5')
        ->call('createDelivery')
        ->assertHasNoErrors()
        ->assertSee('Pak Budi');

    $note = $sale->deliveryNotes()->sole();
    $this->get(route('delivery-notes.print', $note))->assertOk()->assertSee('Semen 50kg')->assertDontSee('65.000');
});

test('the API serves orders, serial lookups, and available serials', function () {
    $phone = Product::factory()->create(['name' => 'HP API', 'price' => 1_500_000, 'stock' => 0, 'track_serial' => true]);
    app(StockService::class)->adjust($phone, StockMovementType::StockIn, 1, $this->cashier, batch: ['serials' => ['API-1']]);
    apiActingAs('admin');

    $this->getJson('/api/v1/pos/products/lookup?code=api-1')->assertOk()->assertJsonPath('data.id', $phone->id)->assertJsonPath('data.matched_serial', 'API-1')->assertJsonPath('data.track_serial', true);
    $this->getJson("/api/v1/pos/products/{$phone->id}/serials")->assertOk()->assertJsonPath('data', ['API-1']);

    $this->postJson('/api/v1/orders', ['customer_name' => 'Mas Dodi', 'type' => 'service', 'device' => 'iPhone 11'])
        ->assertCreated()
        ->assertJsonPath('data.type', 'service')
        ->assertJsonPath('data.status', 'new');

    $id = CustomerOrder::query()->value('id');
    $this->putJson("/api/v1/orders/{$id}/status", ['status' => 'ready'])->assertOk()->assertJsonPath('data.status', 'ready');
    $this->getJson("/api/v1/orders/{$id}/cart")->assertOk()->assertJsonPath('data.customer_order_id', $id);
});
