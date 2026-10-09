<?php

use App\Enums\StockMovementType;
use App\Livewire\Kitchen\KitchenBoard;
use App\Livewire\MasterData\ModifierGroups;
use App\Livewire\MasterData\Products;
use App\Livewire\Pos\Cashier;
use App\Models\KitchenTicket;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Pos\PosException;
use App\Services\Pos\SaleService;
use App\Services\Pos\ShiftService;
use App\Services\Pos\StockService;
use App\Support\Features;
use Illuminate\Support\Str;
use Livewire\Livewire;

function orderSale(User $cashier, array $items, array $extra = [], int $cash = 1_000_000)
{
    return app(SaleService::class)->checkout($cashier, [
        'client_uuid' => (string) Str::uuid(),
        'items' => $items,
        'payments' => [['method' => 'cash', 'amount' => $cash]],
        ...$extra,
    ]);
}

function orderRejection(callable $callback): PosException
{
    try {
        $callback();
    } catch (PosException $exception) {
        return $exception;
    }

    throw new RuntimeException('Checkout should have been rejected.');
}

beforeEach(function () {
    Features::setEnabled(['business.modifiers', 'business.order-type', 'business.tiered-price', 'business.components']);
    $this->cashier = actingAsRole('admin');
    app(ShiftService::class)->open($this->cashier, 0);

    $this->beans = Product::factory()->create(['name' => 'Biji Kopi', 'unit' => 'gram', 'price' => 0, 'stock' => 0, 'track_stock' => true]);
    app(StockService::class)->adjust($this->beans, StockMovementType::StockIn, 1000, $this->cashier);

    $this->latte = Product::factory()->create(['name' => 'Latte', 'price' => 28000, 'track_stock' => false]);
    $this->size = ModifierGroup::query()->create(['name' => 'Ukuran', 'min_select' => 1, 'max_select' => 1]);
    $this->regular = $this->size->modifiers()->create(['name' => 'Regular', 'price' => 0]);
    $this->large = $this->size->modifiers()->create(['name' => 'Large', 'price' => 5000]);
    $this->extras = ModifierGroup::query()->create(['name' => 'Tambahan', 'min_select' => 0, 'max_select' => null]);
    $this->shot = $this->extras->modifiers()->create(['name' => 'Extra Shot', 'price' => 6000, 'product_id' => $this->beans->id, 'ingredient_quantity' => 18]);
    $this->latte->modifierGroups()->attach([$this->size->id => ['sort_order' => 1], $this->extras->id => ['sort_order' => 2]]);
});

test('modifiers add to the line price, keep a snapshot, and their ingredients come back on void', function () {
    $sale = orderSale($this->cashier, [[
        'product_id' => $this->latte->id, 'quantity' => 2, 'price' => 28000,
        'modifiers' => [['id' => $this->large->id, 'price' => 5000], ['id' => $this->shot->id, 'price' => 6000]],
    ]]);

    $item = $sale->items->sole();

    expect($sale->total)->toBe(78000)
        ->and($item->modifiers_total)->toBe(11000)
        ->and($item->modifierSummary())->toBe('Large, Extra Shot')
        ->and((float) $this->beans->fresh()->stock)->toBe(964.0);

    app(SaleService::class)->void($sale, $this->cashier, 'Salah pesan');

    expect((float) $this->beans->fresh()->stock)->toBe(1000.0);
});

test('online checkout enforces required groups, current prices, and existing modifiers', function () {
    $line = ['product_id' => $this->latte->id, 'quantity' => 1, 'price' => 28000];

    expect(orderRejection(fn () => orderSale($this->cashier, [$line]))->reason)->toBe('modifier_required');

    $this->large->update(['price' => 7000]);
    $changed = orderRejection(fn () => orderSale($this->cashier, [[...$line, 'modifiers' => [['id' => $this->large->id, 'price' => 5000]]]]));
    expect($changed->reason)->toBe('price_changed')
        ->and($changed->context['modifier_prices'][$this->large->id]['price'])->toBe(7000);

    $this->large->delete();
    $gone = orderRejection(fn () => orderSale($this->cashier, [[...$line, 'modifiers' => [['id' => $this->large->id, 'price' => 7000]]]]));
    expect($gone->reason)->toBe('modifier_unavailable')
        ->and($gone->context['modifier_ids'])->toBe([$this->large->id]);
});

test('an offline sale with a deleted modifier keeps the cashier snapshot and is flagged', function () {
    $this->large->delete();

    $sale = orderSale($this->cashier, [[
        'product_id' => $this->latte->id, 'quantity' => 1, 'price' => 28000,
        'modifiers' => [['id' => $this->large->id, 'name' => 'Large', 'price' => 5000]],
    ]], ['offline' => true]);

    expect($sale->total)->toBe(33000)
        ->and($sale->items->sole()->modifiers[0]['name'])->toBe('Large')
        ->and($sale->flags)->toContain('modifier_snapshot');
});

test('tier prices apply once the base-unit quantity reaches the threshold', function () {
    $water = Product::factory()->create(['name' => 'Air Mineral', 'price' => 4000, 'track_stock' => false]);
    $water->priceTiers()->createMany([['min_quantity' => 12, 'price' => 3500], ['min_quantity' => 24, 'price' => 3200]]);

    $stale = orderRejection(fn () => orderSale($this->cashier, [['product_id' => $water->id, 'quantity' => 12, 'price' => 4000]]));
    expect($stale->reason)->toBe('price_changed')
        ->and($stale->context['prices'][$water->id])->toMatchArray(['price' => 3500, 'base_price' => 4000]);

    $sale = orderSale($this->cashier, [
        ['product_id' => $water->id, 'quantity' => 20, 'price' => 3200],
        ['product_id' => $water->id, 'quantity' => 4, 'price' => 3200, 'note' => 'dingin'],
    ]);

    expect($sale->total)->toBe(24 * 3200);
});

test('a compound product deducts its components and a void puts them back', function () {
    $paracetamol = Product::factory()->create(['name' => 'Paracetamol', 'unit' => 'tablet', 'stock' => 0, 'track_stock' => true]);
    app(StockService::class)->adjust($paracetamol, StockMovementType::StockIn, 100, $this->cashier);
    $puyer = Product::factory()->create(['name' => 'Puyer Demam', 'unit' => 'bungkus', 'price' => 3000, 'track_stock' => false]);
    $puyer->components()->create(['component_id' => $paracetamol->id, 'quantity' => 0.5]);

    $sale = orderSale($this->cashier, [['product_id' => $puyer->id, 'quantity' => 10, 'price' => 3000]]);
    expect((float) $paracetamol->fresh()->stock)->toBe(95.0);

    app(SaleService::class)->void($sale, $this->cashier, 'Batal');
    expect((float) $paracetamol->fresh()->stock)->toBe(100.0);
});

test('a compound product is refused when a component runs out', function () {
    $tablet = Product::factory()->create(['name' => 'CTM', 'stock' => 0, 'track_stock' => true]);
    $puyer = Product::factory()->create(['name' => 'Puyer Flu', 'price' => 3000, 'track_stock' => false]);
    $puyer->components()->create(['component_id' => $tablet->id, 'quantity' => 1]);

    expect(orderRejection(fn () => orderSale($this->cashier, [['product_id' => $puyer->id, 'quantity' => 1, 'price' => 3000]]))->reason)->toBe('insufficient_stock');
});

test('dine-in orders pay service charge, get a queue number, and only new items go to the kitchen', function () {
    Setting::putMany(['pos.tax_enabled' => '1', 'pos.tax_rate' => '10', 'pos.service_charge_rate' => '5', 'pos.service_charge_dine_in_only' => '1']);
    $line = ['product_id' => $this->latte->id, 'quantity' => 2, 'price' => 28000, 'modifiers' => [['id' => $this->regular->id, 'name' => 'Regular', 'price' => 0]]];
    $signature = $this->latte->id.':0:'.$this->regular->id.':';

    $dineIn = orderSale($this->cashier, [$line], ['order_type' => 'dine_in', 'table_label' => '5', 'kitchen_sent' => [$signature => 1]]);
    $takeAway = orderSale($this->cashier, [$line], ['order_type' => 'take_away']);

    expect($dineIn->service_charge_amount)->toBe(2800)
        ->and($dineIn->tax_amount)->toBe(5880)
        ->and($dineIn->total)->toBe(64680)
        ->and($dineIn->orderLabel())->toBe('Meja 5')
        ->and($takeAway->service_charge_amount)->toBe(0)
        ->and($takeAway->queue_number)->toBe($dineIn->queue_number + 1)
        ->and($dineIn->kitchenTickets->sole()->items[0]['quantity'])->toEqual(1)
        ->and($dineIn->kitchenTickets->sole()->label)->toBe('Meja 5');
});

test('holding orders for the same table merges them into one open bill visible to every cashier', function () {
    $cart = fn (int $quantity) => [
        'items' => [['product_id' => $this->latte->id, 'name' => 'Latte', 'unit' => 'cup', 'price' => 28000, 'quantity' => $quantity, 'modifiers' => [['id' => $this->large->id, 'name' => 'Large', 'price' => 5000]]]],
        'orderType' => 'dine_in',
        'table' => '7',
        'total' => 33000 * $quantity,
    ];

    Livewire::test(Cashier::class)->call('holdOrder', $cart(1))->assertReturned(fn ($result) => $result['ok'] && ! $result['merged'] && $result['ticket_url']);

    $other = actingAsRole('kasir');
    app(ShiftService::class)->open($other, 0);
    $component = Livewire::test(Cashier::class);
    $component->call('holdOrder', $cart(2))->assertReturned(fn ($result) => $result['merged'] && $result['label'] === 'Meja 7');

    $tickets = KitchenTicket::query()->orderBy('id')->get();
    expect($tickets)->toHaveCount(2)
        ->and($tickets[1]->items[0]['quantity'])->toEqual(2);

    $held = $component->instance()->heldOrders();
    expect($held)->toHaveCount(1)->and($held[0]['table'])->toBe('7');

    $resumed = $component->instance()->resumeHeldOrder($held[0]['id']);
    expect($resumed['items'][0]['quantity'])->toEqual(3)
        ->and(array_values($resumed['kitchenSent']))->toEqual([3]);
});

test('the kitchen board lists pending tickets and marks them done', function () {
    $ticket = KitchenTicket::query()->create(['label' => 'Meja 2', 'order_type' => 'dine_in', 'items' => [['name' => 'Latte', 'quantity' => 1, 'unit' => 'cup', 'modifiers' => ['Large'], 'note' => null]]]);

    Livewire::test(KitchenBoard::class)
        ->assertSee('Meja 2')
        ->call('markDone', $ticket->id);

    expect($ticket->fresh()->isDone())->toBeTrue();
    $this->get(route('print.kitchen-ticket', $ticket))->assertOk()->assertSee('Meja 2');
});

test('the modifier page saves a group with options and products', function () {
    Livewire::test(ModifierGroups::class)
        ->call('openCreateModal')
        ->set('name', 'Gula')
        ->set('min_select', '0')
        ->set('max_select', '1')
        ->set('options.0.name', 'Less Sugar')
        ->call('addOption')
        ->set('options.1.name', 'Extra Gula')
        ->set('options.1.price', '2.000')
        ->call('toggleProduct', $this->latte->id)
        ->call('save')
        ->assertHasNoErrors();

    $group = ModifierGroup::query()->where('name', 'Gula')->sole();
    expect($group->modifiers->pluck('price', 'name')->all())->toBe(['Less Sugar' => 0, 'Extra Gula' => 2000])
        ->and($group->products()->pluck('products.id')->all())->toBe([$this->latte->id]);
});

test('the product form saves tier prices, modifier groups, and components', function () {
    Livewire::test(Products::class)
        ->call('openEditModal', $this->latte->id)
        ->call('addTier')
        ->set('price_tiers.0.min_quantity', '10')
        ->set('price_tiers.0.price', '30000')
        ->call('save')
        ->assertHasErrors(['price_tiers.0.price']);

    Livewire::test(Products::class)
        ->call('openEditModal', $this->latte->id)
        ->call('addTier')
        ->set('price_tiers.0.min_quantity', '10')
        ->set('price_tiers.0.price', '25000')
        ->set('modifier_group_ids', [(string) $this->extras->id])
        ->call('addComponent')
        ->set('components.0.component_id', (string) $this->beans->id)
        ->set('components.0.quantity', '18')
        ->call('save')
        ->assertHasNoErrors();

    $latte = $this->latte->fresh();
    expect($latte->priceTiers->pluck('price')->all())->toBe([25000])
        ->and($latte->modifierGroups->pluck('id')->all())->toBe([$this->extras->id])
        ->and((float) $latte->components->sole()->quantity)->toBe(18.0);
});

test('the cashier API exposes tiers, modifier groups, and order settings', function () {
    Setting::put('pos.service_charge_rate', '5');
    apiActingAs('kasir');

    $this->getJson('/api/v1/pos/products?ids='.$this->latte->id)
        ->assertOk()
        ->assertJsonPath('data.0.modifier_groups.0.name', 'Ukuran')
        ->assertJsonPath('data.0.modifier_groups.0.min', 1)
        ->assertJsonPath('data.0.modifier_groups.1.modifiers.0.price', 6000);

    $this->getJson('/api/v1/pos/config')
        ->assertOk()
        ->assertJsonPath('data.order_type_enabled', true)
        ->assertJsonPath('data.service_charge_rate', 5);
});

test('modifier groups can be managed through the API', function () {
    apiActingAs('admin');

    $id = $this->postJson('/api/v1/master-data/modifier-groups', [
        'name' => 'Topping',
        'min_select' => 0,
        'max_select' => null,
        'options' => [['name' => 'Boba', 'price' => 4000], ['name' => 'Keju', 'price' => 5000]],
        'product_ids' => [$this->latte->id],
    ])->assertCreated()->assertJsonPath('data.rule', 'Opsional')->assertJsonPath('data.options.1.price', 5000)->json('data.id');

    $boba = $this->getJson("/api/v1/master-data/modifier-groups/{$id}")->assertOk()->assertJsonPath('data.products.0.id', $this->latte->id)->json('data.options.0.id');

    $this->putJson("/api/v1/master-data/modifier-groups/{$id}", [
        'name' => 'Topping',
        'min_select' => 1,
        'max_select' => 0,
        'options' => [['id' => $boba, 'name' => 'Boba', 'price' => 4500]],
    ])->assertUnprocessable()->assertJsonValidationErrors('max_select');

    $this->putJson("/api/v1/master-data/modifier-groups/{$id}", [
        'name' => 'Topping',
        'min_select' => 0,
        'max_select' => 2,
        'options' => [['id' => $boba, 'name' => 'Boba', 'price' => 4500]],
    ])->assertOk()->assertJsonCount(1, 'data.options')->assertJsonPath('data.products_count', 1);

    $this->deleteJson("/api/v1/master-data/modifier-groups/{$id}")->assertNoContent();
    expect(ModifierGroup::query()->find($id))->toBeNull();
});

test('the kitchen API lists pending tickets for the outlet and marks them done', function () {
    $ticket = KitchenTicket::query()->create(['label' => 'Meja 3', 'order_type' => 'dine_in', 'items' => [['name' => 'Latte', 'quantity' => 2, 'unit' => 'cup', 'modifiers' => ['Large'], 'note' => null]]]);
    $sale = orderSale($this->cashier, [['product_id' => $this->latte->id, 'quantity' => 1, 'price' => 28000, 'modifiers' => [['id' => $this->regular->id, 'price' => 0]]]], ['order_type' => 'take_away']);
    apiActingAs('admin');

    $this->getJson('/api/v1/pos/kitchen-tickets')->assertOk()->assertJsonPath('data.0.label', 'Meja 3')->assertJsonPath('data.0.items.0.modifiers.0', 'Large');
    $this->postJson("/api/v1/pos/kitchen-tickets/{$ticket->id}/done")->assertOk()->assertJsonPath('data.status', 'done');
    $this->getJson('/api/v1/pos/kitchen-tickets?status=done')->assertOk()->assertJsonPath('data.0.id', $ticket->id);
    $this->getJson("/api/v1/sales/{$sale->id}")->assertOk()->assertJsonPath('data.kitchen_tickets.0.order_type', 'take_away');
});
