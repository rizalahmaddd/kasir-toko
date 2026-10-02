<?php

use App\Livewire\Inventory\StockIndex;
use App\Livewire\MasterData\Products;
use App\Livewire\Pos\Cashier;
use App\Livewire\Settings\PosSettingsPage;
use App\Models\CashShift;
use App\Models\Category;
use App\Models\HeldOrder;
use App\Models\Product;
use App\Models\Sale;
use App\Services\DocumentNumberGenerator;
use App\Support\PosSettings;
use Livewire\Livewire;

test('admin can open every pos page with demo data', function () {
    seedDemoTenant();
    actingAsAdmin();

    $sale = Sale::query()->first();
    $shift = CashShift::query()->first();

    foreach ([
        route('dashboard'), route('pos.cashier'), route('sales.index'), route('sales.show', $sale),
        route('shifts.index'), route('shifts.show', $shift), route('shifts.print', $shift), route('pos.receipt', $sale),
        route('receivables.index'), route('master-data.products'), route('master-data.categories'),
        route('inventory.stock'), route('inventory.stock', ['product' => $sale->items->first()->product_id]),
        route('reports.sales'), route('settings.pos'),
    ] as $url) {
        $this->get($url)->assertOk();
    }
});

test('cashier role cannot reach owner pages', function () {
    actingAsRole('kasir');

    $this->get(route('pos.cashier'))->assertOk();
    $this->get(route('reports.sales'))->assertForbidden();
    $this->get(route('settings.pos'))->assertForbidden();
});

test('creating a product with opening stock writes the stock card and an automatic sku', function () {
    actingAsAdmin();
    $category = Category::factory()->create();

    Livewire::test(Products::class)
        ->call('openCreateModal')
        ->set('name', 'Kopi Bubuk 200g')
        ->set('category_id', (string) $category->id)
        ->set('price', '25.000')
        ->set('cost_price', '18000')
        ->set('stock', '12,5')
        ->set('barcode', '8991234567890')
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::where('name', 'Kopi Bubuk 200g')->sole();
    expect($product->sku)->toStartWith('PRD-')
        ->and($product->price)->toBe(25000)
        ->and((float) $product->stock)->toBe(12.5)
        ->and($product->stockMovements()->sole()->type->value)->toBe('initial');

    Livewire::test(Products::class)
        ->call('openCreateModal')
        ->set('name', 'Duplikat')
        ->set('price', '1000')
        ->set('barcode', '8991234567890')
        ->call('save')
        ->assertHasErrors('barcode');
});

test('staff without manage permission cannot change products', function () {
    actingAsRole('kasir');

    Livewire::test(Products::class)->call('openCreateModal')->assertForbidden();
});

test('stock page adjusts stock for users with inventory access only', function () {
    $product = Product::factory()->create(['stock' => 5]);

    actingAsRole('staff');
    Livewire::test(StockIndex::class)
        ->call('openAdjust', $product->id, 'stock_in')
        ->set('adjustQuantity', '2,5')
        ->call('saveAdjustment')
        ->assertHasNoErrors();
    expect((float) $product->fresh()->stock)->toBe(7.5);

    actingAsRole('kasir');
    Livewire::test(StockIndex::class)->call('openAdjust', $product->id)->assertForbidden();
});

test('cashier finds products by barcode or sku and keeps held orders private', function () {
    $cashier = actingAsRole('kasir');
    $product = Product::factory()->create(['barcode' => '1234567890123', 'sku' => 'SKU-ABC']);

    $component = Livewire::test(Cashier::class);
    expect($component->instance()->findByCode('1234567890123')['id'])->toBe($product->id)
        ->and($component->instance()->findByCode('SKU-ABC')['id'])->toBe($product->id)
        ->and($component->instance()->findByCode('nope'))->toBeNull();

    $result = $component->instance()->holdOrder(['items' => [['product_id' => $product->id, 'quantity' => 1]], 'total' => 5000], 'Meja 2');
    expect($result['ok'])->toBeTrue();

    $held = HeldOrder::sole();
    actingAsRole('kasir');
    expect(Livewire::test(Cashier::class)->instance()->resumeHeldOrder($held->id))->toBeNull();

    $this->actingAs($cashier);
    expect(Livewire::test(Cashier::class)->instance()->resumeHeldOrder($held->id)['items'])->toHaveCount(1)
        ->and(HeldOrder::count())->toBe(0);
});

test('quick add customer rejects duplicate phone numbers', function () {
    actingAsRole('kasir');
    $cashier = Livewire::test(Cashier::class)->instance();

    expect($cashier->quickAddCustomer('Bu Rina', '0812-3456-7890', app(DocumentNumberGenerator::class))['ok'])->toBeTrue()
        ->and($cashier->quickAddCustomer('Rina lagi', '081234567890', app(DocumentNumberGenerator::class))['ok'])->toBeFalse();
});

test('pos settings are saved and cash stays enabled', function () {
    actingAsAdmin();

    Livewire::test(PosSettingsPage::class)
        ->set('taxEnabled', true)
        ->set('taxRate', '11')
        ->set('paymentMethods', ['qris'])
        ->set('quickCash', '50.000, 20000, abc')
        ->call('save')
        ->assertHasNoErrors();

    expect(PosSettings::taxRate())->toBe(11.0)
        ->and(collect(PosSettings::paymentMethods())->map->value->all())->toBe(['cash', 'qris'])
        ->and(PosSettings::quickCash())->toBe([20000, 50000]);
});
