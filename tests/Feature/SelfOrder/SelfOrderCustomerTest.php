<?php

use App\Enums\StockMovementType;
use App\Models\DiningTable;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\SelfOrder;
use App\Models\Tenant;
use App\Services\Pos\SelfOrderService;
use App\Services\Pos\ShiftService;
use App\Services\Pos\StockService;
use App\Support\CurrentTenant;
use App\Support\Features;
use App\Support\OutletSettings;
use Illuminate\Support\Str;

/**
 * @param  list<array<string, mixed>>  $items
 * @param  array<string, mixed>  $extra
 */
function customerOrders(string $token, array $items, array $extra = [], ?string $device = 'device-a')
{
    // Request JSON di tes tidak membawa cookie kecuali diminta.
    $request = $device === null ? test() : test()->withCredentials()->withCookie('so_device', str_pad($device, 40, 'x'));

    return $request->postJson(route('self-order.order', $token), [
        'client_uuid' => (string) Str::uuid(),
        'customer_name' => 'Rina',
        'items' => $items,
        ...$extra,
    ]);
}

beforeEach(function () {
    Features::setEnabled(['business.modifiers', 'business.order-type', 'business.self-order', 'business.components']);
    $this->cashier = actingAsRole('admin');
    $this->outlet = primaryOutlet();
    OutletSettings::put($this->outlet->id, 'pos.self_order_enabled', '1');
    app(ShiftService::class)->open($this->cashier, 0);

    $this->table = DiningTable::factory()->create(['name' => '05']);
    $this->latte = Product::factory()->untracked()->create(['name' => 'Latte', 'price' => 28000]);
    $this->cake = Product::factory()->create(['name' => 'Cheesecake', 'price' => 20000, 'stock' => 0]);
    app(StockService::class)->adjust($this->cake, StockMovementType::StockIn, 3, $this->cashier);
});

test('a customer reads the menu and submits a dine-in order priced by the server', function () {
    $this->get(route('self-order.table', $this->table->token))->assertOk()->assertSee('Meja 05');

    $this->getJson(route('self-order.menu', $this->table->token))
        ->assertOk()
        ->assertJsonPath('open', true)
        ->assertJsonCount(2, 'products');

    $response = customerOrders($this->table->token, [
        ['product_id' => $this->latte->id, 'quantity' => 2],
        ['product_id' => $this->cake->id, 'quantity' => 1, 'note' => 'Tanpa garpu'],
    ])->assertCreated();

    $order = SelfOrder::query()->sole();

    expect($order->status)->toBe(SelfOrder::STATUS_PENDING)
        ->and($order->payment_status)->toBe(SelfOrder::PAYMENT_UNPAID)
        ->and($order->order_type)->toBe('dine_in')
        ->and($order->table_label)->toBe('05')
        ->and($order->total)->toBe(76000)
        ->and($order->queue_number)->toBe(1)
        ->and($order->items)->toHaveCount(2)
        ->and($response->json('session_token'))->toBe($order->session_token);

    $this->getJson(route('self-order.state', $order->session_token))
        ->assertOk()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('total', 76000)
        ->assertJsonMissingPath('session_token');
});

test('the standee token orders take away and a repeated client_uuid does not create a second order', function () {
    $payload = ['client_uuid' => (string) Str::uuid(), 'customer_name' => 'Budi', 'items' => [['product_id' => $this->latte->id, 'quantity' => 1]]];
    $url = route('self-order.order', $this->outlet->selfOrderToken());

    $device = str_repeat('s', 40);
    $first = $this->withCredentials()->withCookie('so_device', $device)->postJson($url, $payload)->assertCreated();
    $second = $this->withCredentials()->withCookie('so_device', $device)->postJson($url, $payload)->assertCreated();

    expect(SelfOrder::query()->count())->toBe(1)
        ->and(SelfOrder::query()->sole()->order_type)->toBe('take_away')
        ->and($second->json('session_token'))->toBe($first->json('session_token'));
});

test('the server ignores prices sent by the browser and enforces required modifiers', function () {
    $size = ModifierGroup::query()->create(['name' => 'Ukuran', 'min_select' => 1, 'max_select' => 1]);
    $large = $size->modifiers()->create(['name' => 'Large', 'price' => 5000]);
    $this->latte->modifierGroups()->attach([$size->id => ['sort_order' => 1]]);

    customerOrders($this->table->token, [['product_id' => $this->latte->id, 'quantity' => 1, 'price' => 1]])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'modifier_required');

    customerOrders($this->table->token, [['product_id' => $this->latte->id, 'quantity' => 1, 'price' => 1, 'modifiers' => [$large->id]]])->assertCreated();

    $item = SelfOrder::query()->sole()->items->sole();

    expect($item->price)->toBe(28000)
        ->and($item->modifiers_total)->toBe(5000)
        ->and($item->modifiers[0]['name'])->toBe('Large');
});

test('one shop\'s token never exposes another shop\'s menu, orders, or status', function () {
    $shop = Tenant::factory()->create();
    $foreign = app(CurrentTenant::class)->run($shop, function () use ($shop) {
        Features::setEnabled(['business.order-type', 'business.self-order']);
        $outletId = $shop->outlets()->value('id');

        return [
            'product' => Product::factory()->untracked()->create(['name' => 'Menu Toko Lain']),
            'table' => DiningTable::factory()->create(['name' => '99', 'outlet_id' => $outletId]),
            'order' => SelfOrder::factory()->create(['outlet_id' => $outletId]),
        ];
    });

    $menu = $this->getJson(route('self-order.menu', $this->table->token))->assertOk();
    expect(collect($menu->json('products'))->pluck('name')->all())->not->toContain('Menu Toko Lain');

    customerOrders($this->table->token, [['product_id' => $foreign['product']->id, 'quantity' => 1]])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'unavailable');

    // Kasir toko ini masih login di browser yang sama: konteksnya tetap milik token toko lain.
    $foreignMenu = $this->getJson(route('self-order.menu', $foreign['table']->token))->assertOk();
    expect(collect($foreignMenu->json('products'))->pluck('name')->all())->toBe(['Menu Toko Lain']);

    $state = $this->getJson(route('self-order.state', $foreign['order']->session_token))->assertOk();
    expect($state->json('number'))->toBe($foreign['order']->number);

    expect(SelfOrder::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

test('unknown, malformed, or disabled tokens are not found', function () {
    $this->get(route('self-order.table', Str::random(40)))->assertNotFound();
    $this->get('/pesan/m/short')->assertNotFound();
    $this->getJson(route('self-order.state', Str::random(64)))->assertNotFound();
    // Token standee tidak berlaku di alamat meja, dan sebaliknya.
    $this->get(route('self-order.table', $this->outlet->selfOrderToken()))->assertNotFound();

    $this->table->update(['is_active' => false]);
    $this->get(route('self-order.table', $this->table->token))->assertNotFound();
});

test('regenerating a table token kills the printed QR', function () {
    $old = $this->table->token;
    $this->table->update(['token' => DiningTable::newToken()]);

    $this->get(route('self-order.table', $old))->assertNotFound();
    $this->get(route('self-order.table', $this->table->fresh()->token))->assertOk();
});

test('the page is unavailable when the shop cannot serve QR orders', function (Closure $break) {
    $break($this);

    $this->get(route('self-order.table', $this->table->token))->assertNotFound()->assertSee('tidak tersedia');
    customerOrders($this->table->token, [['product_id' => $this->latte->id, 'quantity' => 1]])->assertNotFound();
    expect(SelfOrder::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'suspended shop' => [fn ($test) => $test->outlet->tenant->update(['status' => Tenant::STATUS_SUSPENDED])],
    'expired subscription' => [fn ($test) => $test->outlet->tenant->update(['plan' => Tenant::PLAN_PRO, 'subscription_ends_at' => now()->subMonth()])],
    'free plan' => [fn ($test) => $test->outlet->tenant->update(['plan' => Tenant::PLAN_FREE])],
    'feature switched off' => [fn () => Features::setEnabled(['business.order-type'])],
    'order type switched off' => [fn () => Features::setEnabled(['business.self-order'])],
    'inactive outlet' => [fn ($test) => $test->outlet->update(['is_active' => false])],
]);

test('the menu stays readable but ordering is closed without a shift or with the switch off', function (Closure $close, string $message) {
    $close($this);

    $this->getJson(route('self-order.menu', $this->table->token))
        ->assertOk()
        ->assertJsonPath('open', false)
        ->assertJsonCount(2, 'products');

    customerOrders($this->table->token, [['product_id' => $this->latte->id, 'quantity' => 1]])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'closed')
        ->assertJsonPath('message', $message);
})->with([
    'no open shift' => [fn ($test) => app(ShiftService::class)->close($test->cashier->openShift(), 0, $test->cashier), 'Kasir belum buka. Pemesanan dibuka setelah kasir memulai shift.'],
    'switch off' => [fn ($test) => OutletSettings::put($test->outlet->id, 'pos.self_order_enabled', '0'), 'Pemesanan lewat QR sedang ditutup.'],
]);

test('an order is refused when stock ran out, including recipe ingredients', function () {
    customerOrders($this->table->token, [['product_id' => $this->cake->id, 'quantity' => 4]])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'insufficient_stock')
        ->assertJsonPath('message', 'Maaf, Cheesecake baru saja habis.');

    $beans = Product::factory()->create(['name' => 'Biji Kopi', 'stock' => 0]);
    app(StockService::class)->adjust($beans, StockMovementType::StockIn, 30, $this->cashier);
    $this->latte->components()->create(['component_id' => $beans->id, 'quantity' => 18]);

    customerOrders($this->table->token, [['product_id' => $this->latte->id, 'quantity' => 2]])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Maaf, Biji Kopi baru saja habis.');

    customerOrders($this->table->token, [['product_id' => $this->latte->id, 'quantity' => 1]])->assertCreated();
});

test('pending orders are capped per table and per device, not per IP', function () {
    $line = [['product_id' => $this->latte->id, 'quantity' => 1]];

    customerOrders($this->table->token, $line, device: 'phone-1')->assertCreated();
    customerOrders($this->table->token, $line, device: 'phone-1')->assertCreated();
    customerOrders($this->table->token, $line, device: 'phone-1')->assertStatus(422)->assertJsonPath('reason', 'quota');

    // Perangkat lain di WiFi (IP) yang sama masih boleh memesan, sampai meja itu sendiri penuh.
    customerOrders($this->table->token, $line, device: 'phone-2')->assertCreated();
    customerOrders($this->table->token, $line, device: 'phone-3')->assertStatus(422)->assertJsonPath('reason', 'quota');

    $other = DiningTable::factory()->create(['name' => '06']);
    customerOrders($other->token, $line, device: 'phone-3')->assertCreated();
});

test('the menu tells a device its pending quota is full before it fills a cart', function () {
    $line = [['product_id' => $this->latte->id, 'quantity' => 1]];
    $menu = fn (string $device) => $this->withCredentials()->withCookie('so_device', str_pad($device, 40, 'x'))->getJson(route('self-order.menu', $this->table->token));

    customerOrders($this->table->token, $line, device: 'phone-1')->assertCreated();
    $menu('phone-1')->assertOk()->assertJsonPath('quota_message', null);

    customerOrders($this->table->token, $line, device: 'phone-1')->assertCreated();
    $menu('phone-1')->assertJsonPath('quota_message', 'Pesanan Anda sebelumnya masih menunggu konfirmasi kasir. Tunggu sampai diproses sebelum memesan lagi.');
    $menu('phone-2')->assertJsonPath('quota_message', null);

    app(SelfOrderService::class)->accept(SelfOrder::query()->oldest('id')->first(), $this->cashier);
    $menu('phone-1')->assertJsonPath('quota_message', null);
});

test('an order without the device cookie from the menu page is refused, and an outlet flood is capped', function () {
    customerOrders($this->table->token, [['product_id' => $this->latte->id, 'quantity' => 1]], device: null)
        ->assertStatus(422)
        ->assertJsonPath('reason', 'device');

    // Halaman menu memasang cookie-nya.
    $this->get(route('self-order.table', $this->table->token))->assertOk()->assertCookie('so_device');

    SelfOrder::factory()->count(SelfOrderService::MAX_PENDING_PER_OUTLET)->create(['outlet_id' => $this->outlet->id]);
    customerOrders($this->table->token, [['product_id' => $this->latte->id, 'quantity' => 1]])->assertStatus(422)->assertJsonPath('reason', 'quota');

    expect(SelfOrder::query()->count())->toBe(SelfOrderService::MAX_PENDING_PER_OUTLET);
});

test('the honeypot field and oversized carts are rejected', function () {
    customerOrders($this->table->token, [['product_id' => $this->latte->id, 'quantity' => 1]], ['website' => 'http://spam.test'])->assertStatus(422);

    $lines = array_fill(0, SelfOrderService::MAX_LINES + 1, ['product_id' => $this->latte->id, 'quantity' => 1]);
    customerOrders($this->table->token, $lines)->assertStatus(422)->assertJsonPath('reason', 'validation');

    expect(SelfOrder::query()->count())->toBe(0);
});

test('a customer cancels only while the order is still pending', function () {
    customerOrders($this->table->token, [['product_id' => $this->latte->id, 'quantity' => 1]])->assertCreated();
    $order = SelfOrder::query()->sole();

    $this->postJson(route('self-order.cancel', $order->session_token))->assertOk()->assertJsonPath('state.status', 'cancelled');
    $this->postJson(route('self-order.cancel', $order->session_token))->assertStatus(422);

    customerOrders($this->table->token, [['product_id' => $this->latte->id, 'quantity' => 1]])->assertCreated();
    $accepted = SelfOrder::query()->where('status', SelfOrder::STATUS_PENDING)->sole();
    app(SelfOrderService::class)->accept($accepted, $this->cashier);

    $this->postJson(route('self-order.cancel', $accepted->session_token))->assertStatus(422)->assertJsonPath('reason', 'self_order_state');
});

test('pending orders past the outlet time limit expire, but claimed QRIS payments wait for the cashier', function () {
    customerOrders($this->table->token, [['product_id' => $this->latte->id, 'quantity' => 1]])->assertCreated();
    $stale = SelfOrder::query()->sole();
    $claimed = SelfOrder::factory()->qris()->create(['outlet_id' => $this->outlet->id]);

    $this->travel(10)->minutes();
    expect(app(SelfOrderService::class)->expireStale())->toBe(0);

    $this->travel(6)->minutes();
    expect(app(SelfOrderService::class)->expireStale())->toBe(1)
        ->and($stale->fresh()->status)->toBe(SelfOrder::STATUS_EXPIRED)
        ->and($claimed->fresh()->status)->toBe(SelfOrder::STATUS_PENDING);
});
