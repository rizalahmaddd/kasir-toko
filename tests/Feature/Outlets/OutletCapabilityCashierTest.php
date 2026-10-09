<?php

use App\Enums\DrugClass;
use App\Livewire\Pos\Cashier;
use App\Livewire\Settings\PosSettingsPage;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Services\BusinessCapabilities;
use App\Services\Pos\CustomerOrderService;
use App\Services\Pos\DeliveryNoteService;
use App\Services\Pos\PosException;
use App\Services\Pos\SaleService;
use App\Services\Pos\ShiftService;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\Navigation;
use App\Support\OutletSettings;
use Illuminate\Support\Str;
use Livewire\Livewire;

function sellAt(Outlet $outlet, User $cashier, Product $product, array $extra = []): Sale
{
    app(CurrentOutlet::class)->set($outlet);
    $shift = $cashier->openShift();

    if ($shift?->outlet_id !== $outlet->id) {
        $shift?->forceFill(['closed_at' => now()])->saveQuietly();
        app(ShiftService::class)->open($cashier, 0);
    }

    return app(SaleService::class)->checkout($cashier, [
        'client_uuid' => (string) Str::uuid(),
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => $product->price]],
        'payments' => [['method' => 'cash', 'amount' => 1_000_000]],
        ...$extra,
    ]);
}

function rejectionAt(callable $callback): PosException
{
    try {
        $callback();
    } catch (PosException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected a rejection.');
}

beforeEach(function () {
    $this->owner = actingAsSuperAdmin();
    $this->tenant->update(['plan' => 'pro']);
    $this->grocery = primaryOutlet();
    $this->pharmacy = makeOutlet(['name' => 'Apotek', 'code' => 'APT']);

    app(BusinessCapabilities::class)->syncOutlet($this->pharmacy, ['business.prescription', 'business.tiered-price', 'business.order-type']);

    $this->medicine = Category::factory()->create(['name' => 'Obat Bebas']);
    $this->medicine->restrictToOutlets([$this->pharmacy->id]);
    $this->paracetamol = Product::factory()->create(['category_id' => $this->medicine->id, 'name' => 'Paracetamol', 'barcode' => '899001', 'price' => 5000, 'track_stock' => false]);
    $this->water = Product::factory()->create(['name' => 'Air Mineral', 'price' => 3000, 'track_stock' => false]);
    $this->antibiotic = Product::factory()->create(['name' => 'Amoxicillin', 'price' => 800, 'track_stock' => false, 'drug_class' => DrugClass::Prescription->value, 'requires_prescription' => true]);
});

test('the cashier only lists products and categories sold at the active outlet', function () {
    app(CurrentOutlet::class)->set($this->grocery);
    $grocery = Livewire::test(Cashier::class)->instance();

    expect($grocery->products->pluck('id')->all())->toBe([$this->water->id])
        ->and($grocery->categories->pluck('id')->all())->not->toContain($this->medicine->id);

    app(CurrentOutlet::class)->set($this->pharmacy);
    $pharmacy = Livewire::test(Cashier::class)->instance();

    expect($pharmacy->products->pluck('id')->all())->toEqualCanonicalizing([$this->water->id, $this->paracetamol->id, $this->antibiotic->id]);
});

test('scanning a product from another outlet explains why it cannot be added', function () {
    app(CurrentOutlet::class)->set($this->grocery);

    Livewire::test(Cashier::class)
        ->call('findByCode', '899001')
        ->assertReturned(['blocked' => 'Paracetamol tidak dijual di outlet ini.']);
});

test('checkout refuses a product not sold at the outlet but keeps offline sales and flags them', function () {
    $rejection = rejectionAt(fn () => sellAt($this->grocery, $this->owner, $this->paracetamol));

    expect($rejection->reason)->toBe('unavailable')
        ->and($rejection->context['product_ids'])->toBe([$this->paracetamol->id]);

    $offline = sellAt($this->grocery, $this->owner, $this->paracetamol, ['offline' => true]);
    expect($offline->fresh()->flags)->toBe(['not_sold_at_outlet']);
});

test('a prescription drug cannot be sold at an outlet without the prescription feature', function () {
    expect(rejectionAt(fn () => sellAt($this->grocery, $this->owner, $this->antibiotic))->reason)->toBe('unavailable')
        ->and(rejectionAt(fn () => sellAt($this->pharmacy, $this->owner, $this->antibiotic))->reason)->toBe('prescription_required');
});

test('order types and wholesale prices follow the outlet of the sale', function () {
    ProductPriceTier::query()->create(['product_id' => $this->water->id, 'min_quantity' => 1, 'price' => 2500]);
    Setting::put('pos.allow_negative_stock', '1');

    $atGrocery = sellAt($this->grocery, $this->owner, $this->water, ['order_type' => 'dine_in']);
    expect($atGrocery->order_type)->toBeNull()
        ->and((int) $atGrocery->total)->toBe(3000);

    $atPharmacy = sellAt($this->pharmacy, $this->owner, $this->water, [
        'order_type' => 'dine_in',
        'items' => [['product_id' => $this->water->id, 'quantity' => 1, 'price' => 2500]],
    ]);
    expect($atPharmacy->order_type)->toBe('dine_in')
        ->and((int) $atPharmacy->total)->toBe(2500);
});

test('the cashier API reports the features and catalog of the outlet in the header', function () {
    $user = apiActingAs('superadmin');
    ProductPriceTier::query()->create(['product_id' => $this->water->id, 'min_quantity' => 5, 'price' => 2500]);

    $this->withHeader('X-Outlet-Id', (string) $this->grocery->id)->getJson('/api/v1/pos/config')
        ->assertOk()->assertJsonPath('data.tiered_price_enabled', false);
    $this->withHeader('X-Outlet-Id', (string) $this->pharmacy->id)->getJson('/api/v1/pos/config')
        ->assertOk()->assertJsonPath('data.tiered_price_enabled', true);

    $this->withHeader('X-Outlet-Id', (string) $this->grocery->id)->getJson('/api/v1/pos/products')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.price_tiers', []);
    $this->withHeader('X-Outlet-Id', (string) $this->grocery->id)->getJson('/api/v1/pos/products/lookup?code=899001')
        ->assertNotFound()->assertJsonPath('message', 'Paracetamol tidak dijual di outlet ini.');

    $this->withHeader('X-Outlet-Id', (string) $this->grocery->id)->getJson("/api/v1/master-data/products/{$this->water->id}")
        ->assertOk()->assertJsonCount(1, 'data.price_tiers');
});

test('operational menus follow the active outlet', function () {
    $labels = fn () => array_column(Navigation::linksForUser($this->owner), 'label');

    app(CurrentOutlet::class)->set($this->grocery);
    expect($labels())->not->toContain('Resep');

    app(CurrentOutlet::class)->set($this->pharmacy);
    expect($labels())->toContain('Resep');
});

test('orders and delivery notes can only be created where the feature is on', function () {
    app(BusinessCapabilities::class)->sync([...Features::enabledKeys(), 'business.pre-order', 'business.delivery-note']);
    app(BusinessCapabilities::class)->syncOutlet($this->pharmacy, ['business.prescription']);
    $sale = sellAt($this->pharmacy, $this->owner, $this->water);

    expect(rejectionAt(fn () => app(DeliveryNoteService::class)->create($sale, $this->owner, ['recipient' => 'Budi', 'address' => 'Jl. Mawar']))->reason)->toBe('feature_off_at_outlet');

    app(CurrentOutlet::class)->set($this->pharmacy);
    expect(rejectionAt(fn () => app(CustomerOrderService::class)->create($this->owner, ['customer_name' => 'Ani']))->reason)->toBe('feature_off_at_outlet');

    app(CurrentOutlet::class)->set($this->grocery);
    expect(app(CustomerOrderService::class)->create($this->owner, ['customer_name' => 'Ani'])->outlet_id)->toBe($this->grocery->id);
});

test('credit and other cashier rules can differ per outlet', function () {
    Setting::put('pos.allow_credit', '1');
    OutletSettings::applySections($this->pharmacy->id, ['rules' => ['inherit' => false, 'allow_credit' => false, 'allow_negative_stock' => false, 'quick_cash' => [5000, 0, 10000]]]);

    $sections = OutletSettings::sections($this->pharmacy->id);
    expect($sections['rules'])->toBe(['inherit' => false, 'allow_credit' => false, 'allow_negative_stock' => false, 'quick_cash' => [5000, 10000]])
        ->and(OutletSettings::sections($this->grocery->id)['rules']['inherit'])->toBeTrue()
        ->and($sections['pharmacy']['inherit'])->toBeTrue();

    $customer = Customer::factory()->create();
    $credit = ['customer_id' => $customer->id, 'payments' => [['method' => 'cash', 'amount' => 0]]];

    expect(rejectionAt(fn () => sellAt($this->pharmacy, $this->owner, $this->water, $credit))->reason)->toBe('underpaid');

    expect(sellAt($this->grocery, $this->owner, $this->water, $credit)->due_amount)->toBeGreaterThan(0);
});

test('the store cashier settings page shows store values, not the active outlet override', function () {
    Setting::put('pos.allow_credit', '1');
    OutletSettings::put($this->pharmacy->id, 'pos.allow_credit', '0');
    app(CurrentOutlet::class)->set($this->pharmacy);

    Livewire::test(PosSettingsPage::class)->assertSet('allowCredit', true);
});

test('the outlet settings API saves cashier and pharmacy rules', function () {
    apiActingAs('superadmin');

    $this->putJson("/api/v1/outlets/{$this->pharmacy->id}/settings", [
        'pharmacy' => ['inherit' => false, 'prescription_mode' => 'warn', 'allow_controlled_drugs' => false, 'block_expired_sale' => true, 'near_expiry_discount_percent' => 10, 'near_expiry_discount_days' => 3],
    ])->assertOk()
        ->assertJsonPath('data.pharmacy.inherit', false)
        ->assertJsonPath('data.pharmacy.prescription_mode', 'warn')
        ->assertJsonPath('data.rules.inherit', true);

    $this->putJson("/api/v1/outlets/{$this->pharmacy->id}/settings", ['pharmacy' => ['inherit' => false, 'prescription_mode' => 'lenient']])
        ->assertUnprocessable()->assertJsonValidationErrors('pharmacy.prescription_mode');
});
