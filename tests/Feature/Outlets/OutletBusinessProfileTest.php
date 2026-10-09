<?php

use App\Enums\StoreType;
use App\Livewire\MasterData\Categories;
use App\Livewire\Settings\Outlets;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Services\BusinessCapabilities;
use App\Services\OutletService;
use App\Services\StorePresetApplier;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\OutletFeatures;
use App\Support\OutletSettings;
use App\Support\ProductAttributes;
use App\Support\StorePresets;
use App\Support\StorePresets\AttributeField;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->owner = actingAsSuperAdmin();
    $this->tenant->update(['plan' => 'pro', 'store_type' => StoreType::Warung]);
    $this->capabilities = app(BusinessCapabilities::class);
});

test('outlets follow the store capability list until one of them gets its own', function () {
    Features::setEnabled(['business.multi-unit']);
    $grocery = primaryOutlet();
    $pharmacy = makeOutlet();

    expect(Features::enabledAt('business.multi-unit', $pharmacy->id))->toBeTrue()
        ->and($grocery->capabilities)->toBeNull();

    $this->capabilities->syncOutlet($pharmacy, ['business.multi-unit', 'business.prescription', 'business.batch-expiry']);

    expect(Features::enabledAt('business.prescription', $pharmacy->id))->toBeTrue()
        ->and(Features::enabledAt('business.prescription', $grocery->id))->toBeFalse()
        ->and(Features::enabledAt('business.multi-unit', $grocery->id))->toBeTrue()
        ->and($grocery->fresh()->capabilities)->toBe(['business.multi-unit'])
        ->and(Features::enabled('business.prescription'))->toBeTrue()
        ->and(Features::enabledKeys())->toEqualCanonicalizing(['business.multi-unit', 'business.prescription', 'business.batch-expiry']);
});

test('enabledAt reads the active outlet when none is given', function () {
    $pharmacy = makeOutlet();
    $this->capabilities->syncOutlet($pharmacy, ['business.prescription']);

    app(CurrentOutlet::class)->set($pharmacy);
    expect(Features::enabledAt('business.prescription'))->toBeTrue();

    app(CurrentOutlet::class)->set(primaryOutlet());
    expect(Features::enabledAt('business.prescription'))->toBeFalse();
});

test('a capability switched off for the whole store is off at every outlet', function () {
    $pharmacy = makeOutlet();
    $this->capabilities->syncOutlet($pharmacy, ['business.prescription', 'business.modifiers']);

    $this->capabilities->sync(['business.modifiers', 'business.tiered-price']);

    expect(Features::enabledAt('business.prescription', $pharmacy->id))->toBeFalse()
        ->and(Features::enabledAt('business.tiered-price', $pharmacy->id))->toBeTrue()
        ->and(Features::enabledAt('business.tiered-price', primaryOutlet()->id))->toBeTrue()
        ->and(Features::enabledAt('business.modifiers', primaryOutlet()->id))->toBeFalse()
        ->and(Features::enabledKeys())->toEqualCanonicalizing(['business.modifiers', 'business.tiered-price']);
});

test('switching a capability off at its last outlet removes it from the store list', function () {
    $pharmacy = makeOutlet();
    $this->capabilities->syncOutlet($pharmacy, ['business.prescription']);

    $this->capabilities->syncOutlet($pharmacy, []);

    expect(Features::enabled('business.prescription'))->toBeFalse();
});

test('cashier features managed by presets can be switched off for one outlet', function () {
    $pharmacy = makeOutlet();

    OutletFeatures::setDisabled($pharmacy, ['pos.receivables', 'pos.sales']);

    expect(Features::enabledAt('pos.receivables', $pharmacy->id))->toBeFalse()
        ->and(Features::enabledAt('pos.receivables', primaryOutlet()->id))->toBeTrue()
        ->and(Features::enabledAt('pos.sales', $pharmacy->id))->toBeTrue()
        ->and($pharmacy->fresh()->disabled_features)->toBe(['pos.receivables']);

    Features::setDisabled(['pos.receivables']);
    expect(Features::enabledAt('pos.receivables', primaryOutlet()->id))->toBeFalse();
});

test('a new outlet copies the primary outlet profile instead of the store-wide list', function () {
    $pharmacy = makeOutlet(['store_type' => StoreType::Pharmacy]);
    $this->capabilities->syncOutlet($pharmacy, ['business.prescription']);
    OutletFeatures::setDisabled(primaryOutlet(), ['pos.customer-display']);

    $branch = app(OutletService::class)->create(['name' => 'Cabang', 'code' => 'CB1'], $this->owner);

    expect($branch->fresh()->capabilities)->toBe([])
        ->and($branch->fresh()->disabled_features)->toBe(['pos.customer-display'])
        ->and($branch->fresh()->store_type)->toBeNull()
        ->and(Features::enabledAt('business.prescription', $branch->id))->toBeFalse();
});

test('a new outlet copied from another outlet takes its store type and capabilities', function () {
    $pharmacy = makeOutlet(['store_type' => StoreType::Pharmacy]);
    $this->capabilities->syncOutlet($pharmacy, ['business.prescription']);

    $branch = app(OutletService::class)->create(['name' => 'Apotek 2', 'code' => 'AP2'], $this->owner, $pharmacy->id);

    expect($branch->fresh()->store_type)->toBe(StoreType::Pharmacy)
        ->and($branch->fresh()->effectiveStoreType())->toBe(StoreType::Pharmacy)
        ->and(primaryOutlet()->effectiveStoreType())->toBe(StoreType::Warung)
        ->and(Features::enabledAt('business.prescription', $branch->id))->toBeTrue();
});

test('deleting the only outlet that used a capability switches it off for the store', function () {
    $pharmacy = makeOutlet();
    $this->capabilities->syncOutlet($pharmacy, ['business.prescription']);

    app(OutletService::class)->delete($pharmacy, $this->owner);

    expect(Features::enabled('business.prescription'))->toBeFalse();
});

test('products are available at an outlet unless their category is limited to other outlets', function () {
    $pharmacy = makeOutlet();
    $medicine = Category::factory()->create();
    $medicine->restrictToOutlets([$pharmacy->id]);
    $drink = Category::factory()->create();

    $paracetamol = Product::factory()->create(['category_id' => $medicine->id]);
    $water = Product::factory()->create(['category_id' => $drink->id]);
    $loose = Product::factory()->create(['category_id' => null]);

    expect(Product::query()->availableAt(primaryOutlet()->id)->pluck('id')->all())->toEqualCanonicalizing([$water->id, $loose->id])
        ->and(Product::query()->availableAt($pharmacy->id)->pluck('id')->all())->toEqualCanonicalizing([$paracetamol->id, $water->id, $loose->id])
        ->and($medicine->isAvailableAt(primaryOutlet()->id))->toBeFalse()
        ->and($drink->isAvailableAt($pharmacy->id))->toBeTrue();

    $medicine->restrictToOutlets([]);
    expect(Product::query()->availableAt(primaryOutlet()->id)->count())->toBe(3);
});

test('product attributes and unit suggestions combine the store type with every outlet type', function () {
    Features::setEnabled(['business.product-attributes']);
    makeOutlet(['store_type' => StoreType::Pharmacy]);

    $keys = array_map(fn (AttributeField $field) => $field->key, ProductAttributes::fields());

    expect($keys)->toContain('brand', 'active_ingredient')
        ->and($keys)->toBe(array_values(array_unique($keys)))
        ->and(ProductAttributes::suggestedUnits())->toContain('renteng', 'strip')
        ->and(ProductAttributes::suggestedUnits())->toBe(array_values(array_unique(ProductAttributes::suggestedUnits())));
});

test('a pharmacy outlet in a grocery store gets its own catalog, features and cashier rules', function () {
    Setting::put('pos.tax_enabled', '1');
    Setting::put('pos.allow_credit', '1');
    $drinks = Category::factory()->create(['name' => 'Minuman']);
    $customerSale = Sale::factory()->create();

    $pharmacy = app(OutletService::class)->create(['name' => 'Apotek Sehat', 'code' => 'APT'], $this->owner, null, ['store_type' => StoreType::Pharmacy]);
    $grocery = primaryOutlet();

    $medicine = Category::query()->where('name', StorePresets::categories(StoreType::Pharmacy)[0])->sole();
    $amoxicillin = Product::query()->where('name', 'Amoxicillin 500mg Kapsul')->sole();

    expect($pharmacy->fresh()->store_type)->toBe(StoreType::Pharmacy)
        ->and($medicine->isAvailableAt($pharmacy->id))->toBeTrue()
        ->and($medicine->isAvailableAt($grocery->id))->toBeFalse()
        ->and($drinks->isAvailableAt($grocery->id))->toBeTrue()
        ->and($amoxicillin->requires_prescription)->toBeTrue()
        ->and(Features::enabledAt('business.prescription', $pharmacy->id))->toBeTrue()
        ->and(Features::enabledAt('business.prescription', $grocery->id))->toBeFalse()
        ->and(Features::enabledAt('pos.receivables', $pharmacy->id))->toBeFalse()
        ->and(OutletSettings::get('pos.allow_credit', $pharmacy->id))->toBe('0')
        ->and(OutletSettings::get('pos.receipt_footer', $pharmacy->id))->toContain('lekas sembuh')
        ->and(OutletSettings::get('pos.tax_enabled', $pharmacy->id))->toBeNull()
        ->and(OutletSettings::get('pos.allow_credit', $grocery->id))->toBeNull()
        ->and(Setting::get('pos.allow_credit'))->toBe('1')
        ->and(Role::query()->where('name', 'apoteker')->exists())->toBeTrue()
        ->and($customerSale->exists)->toBeTrue();
});

test('a category with the same name already limited to another outlet is shared with the new one', function () {
    $other = makeOutlet();
    $shared = Category::factory()->create(['name' => StorePresets::categories(StoreType::Pharmacy)[0]]);
    $shared->restrictToOutlets([$other->id]);

    $pharmacy = makeOutlet();
    app(StorePresetApplier::class)->applyToOutlet($pharmacy, StoreType::Pharmacy, includeSampleProducts: false);

    expect($shared->isAvailableAt($other->id))->toBeTrue()
        ->and($shared->isAvailableAt($pharmacy->id))->toBeTrue()
        ->and($shared->isAvailableAt(primaryOutlet()->id))->toBeFalse();
});

test('a preset applied to an outlet fills sample products only up to the plan limit', function () {
    config(['saas.plans.pro.max_products' => 5]);
    Product::factory()->count(3)->create();

    $result = app(StorePresetApplier::class)->applyToOutlet(makeOutlet(), StoreType::Pharmacy);

    expect($result['products_created'])->toBe(2)
        ->and($result['products_skipped'])->toBe(StorePresets::sampleProductCount(StoreType::Pharmacy) - 2);
});

test('the outlet wizard creates a pharmacy outlet with the features the owner kept', function () {
    Livewire::test(Outlets::class)
        ->call('openCreate')
        ->set('name', 'Apotek Sehat')->set('code', 'apt')
        ->set('storeType', StoreType::Pharmacy->value)
        ->assertSet('presetCapabilities', StorePresets::capabilities(StoreType::Pharmacy))
        ->call('togglePresetCapability', 'business.components')
        ->set('includeSampleProducts', false)
        ->call('nextStep')
        ->assertHasNoErrors()
        ->call('save')
        ->assertHasNoErrors();

    $pharmacy = Outlet::query()->where('code', 'APT')->sole();

    expect($pharmacy->store_type)->toBe(StoreType::Pharmacy)
        ->and(Features::enabledAt('business.prescription', $pharmacy->id))->toBeTrue()
        ->and(Features::enabledAt('business.components', $pharmacy->id))->toBeFalse()
        ->and(Product::query()->count())->toBe(0)
        ->and(Category::query()->where('name', StorePresets::categories(StoreType::Pharmacy)[0])->sole()->isAvailableAt(primaryOutlet()->id))->toBeFalse();
});

test('the outlet wizard keeps the business profile of the primary outlet by default', function () {
    Livewire::test(Outlets::class)
        ->call('openCreate')
        ->assertSee('Sama seperti outlet lain (Warung / Kelontong)')
        ->set('name', 'Cabang')->set('code', 'cb2')
        ->call('save')
        ->assertHasNoErrors();

    expect(Outlet::query()->where('code', 'CB2')->sole()->effectiveStoreType())->toBe(StoreType::Warung)
        ->and(Category::query()->count())->toBe(0);
});

test('the outlet settings form overrides cashier rules and shows pharmacy rules only where they apply', function () {
    $pharmacy = makeOutlet(['name' => 'Apotek']);
    $this->capabilities->syncOutlet($pharmacy, ['business.prescription']);

    Livewire::test(Outlets::class)
        ->call('openConfig', primaryOutlet()->id)
        ->assertDontSee('Aturan obat & kedaluwarsa')
        ->call('openConfig', $pharmacy->id)
        ->assertSee('Aturan obat & kedaluwarsa')
        ->set('inheritRules', false)->set('allowCredit', false)->set('quickCash', '5000, 10000')
        ->set('inheritPharmacy', false)->set('prescriptionMode', 'warn')
        ->call('saveConfig')
        ->assertHasNoErrors();

    expect(OutletSettings::overrides($pharmacy->id))->toMatchArray([
        'pos.allow_credit' => '0',
        'pos.quick_cash' => '[5000,10000]',
        'pos.prescription_mode' => 'warn',
    ]);
});

test('the category form limits a category to chosen outlets and an empty choice opens it to all', function () {
    $pharmacy = makeOutlet(['name' => 'Apotek']);

    Livewire::test(Categories::class)
        ->call('openCreateModal')
        ->assertSee('Dijual di outlet')
        ->set('name', 'Obat Bebas')->set('outlet_ids', [(string) $pharmacy->id])
        ->call('save')
        ->assertHasNoErrors();

    $category = Category::query()->where('name', 'Obat Bebas')->sole();
    expect($category->isAvailableAt(primaryOutlet()->id))->toBeFalse();

    Livewire::test(Categories::class)
        ->call('openEditModal', $category->id)
        ->assertSet('outlet_ids', [(string) $pharmacy->id])
        ->set('outlet_ids', [])
        ->call('save');

    expect($category->isAvailableAt(primaryOutlet()->id))->toBeTrue();
});

test('the category API reads and writes the outlets a category is sold at', function () {
    apiActingAs('superadmin');
    $pharmacy = makeOutlet();

    $id = $this->postJson('/api/v1/master-data/categories', ['name' => 'Obat Keras', 'outlet_ids' => [$pharmacy->id]])
        ->assertCreated()->assertJsonPath('data.outlet_ids', [$pharmacy->id])->json('data.id');

    $this->putJson("/api/v1/master-data/categories/{$id}", ['name' => 'Obat Keras G'])
        ->assertOk()->assertJsonPath('data.outlet_ids', [$pharmacy->id]);

    $this->putJson("/api/v1/master-data/categories/{$id}", ['name' => 'Obat Keras G', 'outlet_ids' => []])
        ->assertOk()->assertJsonPath('data.outlet_ids', []);
});

test('the outlet API creates a pharmacy outlet and reports each outlet business profile', function () {
    apiActingAs('superadmin');

    $id = $this->postJson('/api/v1/outlets', ['name' => 'Apotek', 'code' => 'apt', 'store_type' => 'apotek', 'include_sample_products' => false, 'capabilities' => ['business.prescription']])
        ->assertCreated()
        ->assertJsonPath('data.store_type', 'apotek')
        ->assertJsonPath('data.capabilities', ['business.prescription'])
        ->assertJsonPath('data.disabled_features', ['pos.receivables'])
        ->json('data.id');

    $this->getJson('/api/v1/outlets?scope=all')
        ->assertOk()
        ->assertJsonPath('data.0.store_type', null)
        ->assertJsonPath('data.0.effective_store_type', 'warung')
        ->assertJsonPath('data.0.capabilities', []);

    $this->withHeader('X-Outlet-Id', (string) $id)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.outlet_features', fn (array $features) => in_array('business.prescription', $features, true))
        ->assertJsonPath('data.enabled_features', fn (array $features) => in_array('business.prescription', $features, true));

    $this->withHeader('X-Outlet-Id', (string) primaryOutlet()->id)->getJson('/api/v1/auth/me')
        ->assertJsonPath('data.outlet_features', fn (array $features) => ! in_array('business.prescription', $features, true));

    $this->postJson('/api/v1/outlets', ['name' => 'X', 'code' => 'xx', 'store_type' => 'apotek', 'capabilities' => ['pos.sales']])
        ->assertUnprocessable()->assertJsonValidationErrors('capabilities.0');
});

test('the outlet capabilities API switches features for one outlet only', function () {
    apiActingAs('superadmin');
    $branch = makeOutlet();

    $this->putJson("/api/v1/outlets/{$branch->id}/capabilities", ['capabilities' => ['business.order-type'], 'disabled_features' => ['pos.customer-display']])
        ->assertOk()
        ->assertJsonPath('data.capabilities', ['business.order-type'])
        ->assertJsonPath('data.disabled_features', ['pos.customer-display']);

    expect(Features::enabledAt('business.order-type', primaryOutlet()->id))->toBeFalse();

    apiActingAs('admin');
    $this->putJson("/api/v1/outlets/{$branch->id}/capabilities", ['capabilities' => []])->assertForbidden();
});

test('changing an outlet to another business type only adds features and categories', function () {
    apiActingAs('superadmin');
    $branch = makeOutlet();
    $this->capabilities->syncOutlet($branch, ['business.order-type']);

    $this->putJson("/api/v1/outlets/{$branch->id}", ['name' => $branch->name, 'code' => $branch->code, 'store_type' => 'apotek'])
        ->assertOk()
        ->assertJsonPath('data.store_type', 'apotek');

    expect(Features::enabledAt('business.order-type', $branch->id))->toBeTrue()
        ->and(Features::enabledAt('business.prescription', $branch->id))->toBeTrue()
        ->and(Product::query()->count())->toBe(0);
});

test('the outlet features modal switches one outlet to pharmacy and warns before hiding used data', function () {
    $branch = makeOutlet(['name' => 'Cabang']);

    $component = Livewire::test(Outlets::class)
        ->call('openCapabilities', $branch->id)
        ->assertSet('outletCapabilities', [])
        ->set('outletStoreType', StoreType::Pharmacy->value)
        ->assertSet('outletCapabilities', StorePresets::capabilities(StoreType::Pharmacy))
        ->call('saveCapabilities');

    expect($branch->fresh()->store_type)->toBe(StoreType::Pharmacy)
        ->and(Features::enabledAt('business.prescription', $branch->id))->toBeTrue()
        ->and(Features::enabledAt('business.prescription', primaryOutlet()->id))->toBeFalse();

    Product::factory()->create(['custom_attributes' => ['active_ingredient' => 'Paracetamol']]);

    $component->call('openCapabilities', $branch->id)
        ->call('toggleOutletCapability', 'business.product-attributes')
        ->assertSee('tidak dipakai outlet lain')
        ->call('saveCapabilities');

    expect(Features::enabled('business.product-attributes'))->toBeFalse()
        ->and(Product::query()->whereNotNull('custom_attributes')->count())->toBe(1);
});

test('copying from another outlet can also copy its business type and features', function () {
    $pharmacy = makeOutlet(['store_type' => StoreType::Pharmacy]);
    $this->capabilities->syncOutlet($pharmacy, ['business.prescription']);
    OutletFeatures::setDisabled($pharmacy, ['pos.receivables']);
    $branch = makeOutlet();
    $this->capabilities->syncOutlet($branch, ['business.order-type']);

    app(OutletService::class)->copyConfiguration($pharmacy, $branch);
    expect(Features::enabledAt('business.order-type', $branch->id))->toBeTrue();

    Livewire::test(Outlets::class)
        ->call('openCopy', $branch->id)
        ->set('copySourceId', (string) $pharmacy->id)
        ->set('copyBusiness', true)
        ->call('copyConfiguration');

    expect($branch->fresh()->store_type)->toBe(StoreType::Pharmacy)
        ->and(Features::enabledAt('business.prescription', $branch->id))->toBeTrue()
        ->and(Features::enabledAt('business.order-type', $branch->id))->toBeFalse()
        ->and(Features::enabledAt('pos.receivables', $branch->id))->toBeFalse();
});

test('a user who holds only some outlets cannot change category availability at the others', function () {
    $dago = makeOutlet(['name' => 'Dago']);
    $pharmacy = makeOutlet(['name' => 'Apotek']);

    $shared = Category::factory()->create();
    $shared->restrictToOutletsWithin([$dago->id], [$dago->id]);
    expect($shared->outlets()->count())->toBe(0);

    $medicine = Category::factory()->create();
    $medicine->restrictToOutlets([$pharmacy->id]);
    $medicine->restrictToOutletsWithin([$dago->id], [$dago->id]);
    expect($medicine->isAvailableAt($pharmacy->id))->toBeTrue()
        ->and($medicine->isAvailableAt($dago->id))->toBeTrue()
        ->and($medicine->isAvailableAt(primaryOutlet()->id))->toBeFalse();

    $medicine->restrictToOutletsWithin([], [$dago->id]);
    expect($medicine->isAvailableAt($pharmacy->id))->toBeTrue()
        ->and($medicine->isAvailableAt($dago->id))->toBeFalse();

    $drinks = Category::factory()->create();
    $drinks->restrictToOutletsWithin([], [$dago->id]);
    expect($drinks->outlets()->count())->toBe(0);
});

test('the category API keeps the outlets a limited user does not hold', function () {
    $dago = makeOutlet(['name' => 'Dago']);
    $pharmacy = makeOutlet(['name' => 'Apotek']);
    $medicine = Category::factory()->create(['name' => 'Obat']);
    $medicine->restrictToOutlets([$pharmacy->id]);

    $clerk = User::factory()->limitedToOutlets()->create();
    $clerk->assignRole(seededRole('admin'));
    $clerk->outlets()->attach($dago->id, ['tenant_id' => $this->tenant->id]);
    Sanctum::actingAs($clerk);

    $this->putJson("/api/v1/master-data/categories/{$medicine->id}", ['name' => 'Obat', 'outlet_ids' => [$dago->id, primaryOutlet()->id]])
        ->assertOk()
        ->assertJsonPath('data.outlet_ids', fn (array $ids) => collect($ids)->sort()->values()->all() === collect([$dago->id, $pharmacy->id])->sort()->values()->all());
});
