<?php

use App\Models\HeldOrder;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->cashier = apiActingAs('kasir');
    $this->product = Product::factory()->create(['price' => 15000, 'stock' => 10, 'barcode' => '8990000000011']);
    $this->cart = fn (array $extra = []) => [
        'client_uuid' => (string) Str::uuid(),
        'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'price' => 15000]],
        'payments' => [['method' => 'cash', 'amount' => 50000]],
        ...$extra,
    ];
});

it('refuses checkout until the cashier opens a shift', function () {
    $this->postJson('/api/v1/pos/checkout', ($this->cart)())
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'no_shift');

    $this->getJson('/api/v1/pos/shift')->assertOk()->assertJsonPath('data.shift', null);
});

it('records a sale and replays it for the same client uuid', function () {
    $this->postJson('/api/v1/pos/shift', ['opening_cash' => 100000])->assertCreated();
    $payload = ($this->cart)();

    $sale = $this->postJson('/api/v1/pos/checkout', $payload)
        ->assertCreated()
        ->assertJsonPath('data.total', 30000)
        ->assertJsonPath('data.change_amount', 20000)
        ->json('data');

    $this->postJson('/api/v1/pos/checkout', $payload)->assertOk()->assertJsonPath('data.id', $sale['id']);

    expect(Sale::count())->toBe(1)
        ->and($this->product->fresh()->stock)->toBe('8.000');
});

it('tells the app the new price when it changed since the cart was built', function () {
    $this->postJson('/api/v1/pos/shift', ['opening_cash' => 0]);
    $this->product->update(['price' => 17000]);

    $this->postJson('/api/v1/pos/checkout', ($this->cart)())
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'price_changed')
        ->assertJsonPath("context.prices.{$this->product->id}.price", 17000);
});

it('reports field errors for a malformed cart', function () {
    $this->postJson('/api/v1/pos/checkout', ['payments' => []])
        ->assertJsonValidationErrors(['client_uuid', 'items' => 'Keranjang masih kosong.']);
});

it('finds a product by its scanned barcode', function () {
    $this->getJson('/api/v1/pos/products/lookup?code=8990000000011')->assertOk()->assertJsonPath('data.id', $this->product->id);
    $this->getJson('/api/v1/pos/products/lookup?code=000')->assertNotFound();
});

it('hands a held cart back once and only to its owner', function () {
    $cart = ['items' => [['product_id' => $this->product->id, 'name' => 'Teh', 'price' => 15000, 'quantity' => 1]], 'total' => 15000];
    $id = $this->postJson('/api/v1/pos/held-orders', ['cart' => $cart])
        ->assertCreated()
        ->assertJsonPath('data.label', 'Pesanan #1')
        ->json('data.id');

    Sanctum::actingAs(apiActingAs('kasir'));
    $this->getJson('/api/v1/pos/held-orders')->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/pos/held-orders/{$id}/resume")->assertNotFound();

    Sanctum::actingAs($this->cashier);
    $this->postJson("/api/v1/pos/held-orders/{$id}/resume")->assertOk()->assertJsonPath('data.cart.total', 15000);
    expect(HeldOrder::count())->toBe(0);
});

it('needs a configured QRIS before generating one', function () {
    $this->getJson('/api/v1/pos/qris?amount=15000')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'QRIS toko belum diatur di Pengaturan Kasir.');
});

it('keeps accounts without cashier access out of the cashier endpoints', function () {
    apiActingAs('staff');

    $this->getJson('/api/v1/pos/config')->assertForbidden();
});
