<?php

use App\Enums\StockMovementType;
use App\Livewire\MasterData\Products;
use App\Livewire\Pos\Cashier;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Services\Pos\PosException;
use App\Services\Pos\SaleService;
use App\Services\Pos\ShiftService;
use App\Services\Pos\StockService;
use App\Support\Features;
use Illuminate\Support\Str;
use Livewire\Livewire;

function unitSale(User $cashier, array $items, int $cash = 1_000_000, array $extra = [])
{
    return app(SaleService::class)->checkout($cashier, [
        'client_uuid' => (string) Str::uuid(),
        'items' => $items,
        'payments' => [['method' => 'cash', 'amount' => $cash]],
        ...$extra,
    ]);
}

beforeEach(function () {
    Features::setEnabled(['business.multi-unit']);
    $this->cashier = actingAsRole('admin');
    $this->product = Product::factory()->create(['name' => 'Paracetamol', 'unit' => 'tablet', 'price' => 500, 'cost_price' => 300, 'stock' => 0]);
    app(StockService::class)->adjust($this->product, StockMovementType::StockIn, 300, $this->cashier);
    $this->strip = $this->product->units()->create(['name' => 'strip', 'factor' => 10, 'price' => null, 'sort_order' => 1]);
    $this->box = $this->product->units()->create(['name' => 'box', 'factor' => 100, 'price' => 45000, 'sort_order' => 2]);
    app(ShiftService::class)->open($this->cashier, 0);
});

test('selling in a larger unit uses its price and deducts stock in the base unit', function () {
    $sale = unitSale($this->cashier, [
        ['product_id' => $this->product->id, 'unit_id' => $this->box->id, 'quantity' => 1, 'price' => 45000],
        ['product_id' => $this->product->id, 'unit_id' => $this->strip->id, 'quantity' => 2, 'price' => 5000],
        ['product_id' => $this->product->id, 'quantity' => 3, 'price' => 500],
    ]);

    $box = $sale->items->firstWhere('product_unit_id', $this->box->id);

    expect($sale->total)->toBe(45000 + 10000 + 1500)
        ->and($box->unit)->toBe('box')
        ->and((float) $box->base_quantity)->toBe(100.0)
        ->and($box->cost_price)->toBe(30000)
        ->and((float) $this->product->fresh()->stock)->toBe(300.0 - 100 - 20 - 3);
});

test('stock checks count every unit in the base unit', function () {
    $error = null;

    try {
        unitSale($this->cashier, [['product_id' => $this->product->id, 'unit_id' => $this->box->id, 'quantity' => 4, 'price' => 45000]], 1_000_000);
    } catch (PosException $exception) {
        $error = $exception;
    }

    expect($error?->reason)->toBe('insufficient_stock');
});

test('a changed unit price is reported per unit', function () {
    $this->box->update(['price' => 47000]);

    try {
        unitSale($this->cashier, [['product_id' => $this->product->id, 'unit_id' => $this->box->id, 'quantity' => 1, 'price' => 45000]]);
        $this->fail('Checkout should be rejected.');
    } catch (PosException $exception) {
        expect($exception->reason)->toBe('price_changed')
            ->and($exception->context['unit_prices'][$this->box->id]['price'])->toBe(47000)
            ->and($exception->context['prices'])->toBe([]);
    }
});

test('a deleted unit is rejected online but accepted from the offline queue', function () {
    $this->strip->delete();
    $line = ['product_id' => $this->product->id, 'unit_id' => $this->strip->id, 'quantity' => 1, 'price' => 5000];

    expect(fn () => unitSale($this->cashier, [$line]))->toThrow(PosException::class, 'satuan jual');

    $sale = unitSale($this->cashier, [$line], extra: ['offline' => true]);
    expect((float) $sale->items->sole()->base_quantity)->toBe(10.0);
});

test('voiding a unit sale returns the base quantity', function () {
    $sale = unitSale($this->cashier, [['product_id' => $this->product->id, 'unit_id' => $this->box->id, 'quantity' => 2, 'price' => 45000]]);

    app(SaleService::class)->void($sale, $this->cashier, 'Salah input');

    expect((float) $this->product->fresh()->stock)->toBe(300.0);
});

test('stock in by unit converts quantity and purchase price to the base unit', function () {
    $movement = app(StockService::class)->adjust($this->product, StockMovementType::StockIn, 2, $this->cashier, 'Dari PBF', 25000, null, $this->box);

    expect((float) $movement->quantity)->toBe(200.0)
        ->and($movement->unit_cost)->toBe(250);
});

test('the product form saves units and rejects a barcode already used elsewhere', function () {
    Product::factory()->create(['barcode' => '8990001']);

    Livewire::test(Products::class)
        ->call('openEditModal', $this->product->id)
        ->assertSet('units.1.name', 'box')
        ->set('units.0.barcode', '8990001')
        ->call('save')
        ->assertHasErrors(['units.0.barcode']);

    Livewire::test(Products::class)
        ->call('openEditModal', $this->product->id)
        ->call('removeUnit', 0)
        ->set('units.0.barcode', '8990002')
        ->set('units.0.is_default_sale', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->product->units()->pluck('name')->all())->toBe(['box'])
        ->and(ProductUnit::withTrashed()->find($this->strip->id)->trashed())->toBeTrue()
        ->and($this->box->fresh()->barcode)->toBe('8990002')
        ->and($this->box->fresh()->is_default_sale)->toBeTrue();
});

test('the cashier lookup finds a product by its unit barcode', function () {
    $this->box->update(['barcode' => 'BOX-PCT']);
    apiActingAs('kasir');

    $this->getJson('/api/v1/pos/products/lookup?code=BOX-PCT')
        ->assertOk()
        ->assertJsonPath('data.id', $this->product->id)
        ->assertJsonPath('data.matched_unit_id', $this->box->id)
        ->assertJsonPath('data.units.1.price', 45000)
        ->assertJsonPath('data.units.0.price', 5000);
});

test('units stay hidden from the cashier while multi-unit is switched off', function () {
    Features::setEnabled([]);

    expect(Cashier::productPayload($this->product->load('units'))['units'])->toBe([]);
});
