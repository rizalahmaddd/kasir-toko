<?php

use App\Enums\DrugClass;
use App\Enums\StoreType;
use App\Livewire\MasterData\Products;
use App\Livewire\Settings\FeatureToggles;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\Tenant;
use App\Services\StorePresetApplier;
use App\Support\CurrentTenant;
use App\Support\Features;
use App\Support\StorePresets;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

test('business capabilities stay off for existing shops until switched on', function () {
    actingAsSuperAdmin();

    foreach (Features::optInFeatures() as $key) {
        expect(Features::enabled($key))->toBeFalse($key);
    }

    expect(Features::enabled('business'))->toBeFalse()
        ->and(Features::enabled('pos.cashier'))->toBeTrue();
});

test('the feature page switches capabilities on individually and "enable all" leaves them alone', function () {
    actingAsSuperAdmin();

    Livewire::test(FeatureToggles::class)
        ->call('enableAll')
        ->call('save');
    expect(Features::enabledKeys())->toBe([]);

    Livewire::test(FeatureToggles::class)
        ->set('state.business.features.multi-unit', true)
        ->call('save');

    expect(Features::enabled('business.multi-unit'))->toBeTrue()
        ->and(Features::enabled('business.batch-expiry'))->toBeFalse();
});

test('applying the pharmacy preset turns on its capabilities and builds rich sample data', function () {
    $tenant = Tenant::factory()->pendingOnboarding()->create();

    $result = app(StorePresetApplier::class)->apply($tenant, StoreType::Pharmacy);

    app(CurrentTenant::class)->run($tenant, function () use ($result) {
        $paracetamol = Product::query()->where('name', 'Paracetamol 500mg Tablet')->with('units')->sole();
        $amoxicillin = Product::query()->where('name', 'Amoxicillin 500mg Kapsul')->sole();

        expect($result['capabilities'])->toEqualCanonicalizing(StorePresets::capabilities(StoreType::Pharmacy))
            ->and(Features::enabled('business.prescription'))->toBeTrue()
            ->and($paracetamol->units->pluck('factor', 'name')->map(fn ($factor) => (float) $factor)->all())->toBe(['strip' => 10.0, 'box' => 100.0])
            ->and($paracetamol->custom_attributes['active_ingredient'])->toBe('Paracetamol')
            ->and($paracetamol->track_batch)->toBeTrue()
            ->and($amoxicillin->drug_class)->toBe(DrugClass::Prescription->value)
            ->and($amoxicillin->requires_prescription)->toBeTrue()
            ->and(Product::query()->search('amoxicillin trihidrat')->pluck('id')->all())->toBe([$amoxicillin->id])
            ->and(Role::query()->where('tenant_id', app(CurrentTenant::class)->id())->whereIn('name', ['apoteker', 'asisten-apoteker'])->count())->toBe(2)
            ->and(Role::findByName('apoteker')->hasPermissionTo('pharmacy.prescription.verify'))->toBeTrue();
    });
});

test('capabilities the owner unticks are left off and their sample data is not created', function () {
    $tenant = Tenant::factory()->pendingOnboarding()->create();

    app(StorePresetApplier::class)->apply($tenant, StoreType::Pharmacy, capabilities: ['business.product-attributes']);

    app(CurrentTenant::class)->run($tenant, function () {
        expect(Features::enabledKeys())->toBe(['business.product-attributes'])
            ->and(ProductUnit::query()->count())->toBe(0)
            ->and(Product::query()->where('requires_prescription', true)->count())->toBe(0)
            ->and(Product::query()->where('track_batch', true)->count())->toBe(0);
    });
});

test('re-applying another preset keeps capabilities that already hold data', function () {
    $tenant = Tenant::factory()->pendingOnboarding()->create();
    $applier = app(StorePresetApplier::class);
    $applier->apply($tenant, StoreType::Pharmacy);

    $result = $applier->apply($tenant->refresh(), StoreType::Cafe);

    app(CurrentTenant::class)->run($tenant, function () use ($result) {
        expect($result['capabilities_kept'])->toContain('business.multi-unit')
            ->and(Features::enabled('business.multi-unit'))->toBeTrue()
            ->and(Features::enabled('business.prescription'))->toBeFalse();
    });
});

test('product attributes follow the store type schema and stay when it changes', function () {
    $user = actingAsSuperAdmin();
    $user->tenant->forceFill(['store_type' => StoreType::Pharmacy])->save();
    app(CurrentTenant::class)->set($user->tenant->fresh());
    Features::setEnabled(['business.product-attributes', 'business.prescription']);
    $product = Product::factory()->create(['custom_attributes' => ['legacy_key' => 'tetap']]);

    Livewire::test(Products::class)
        ->call('openEditModal', $product->id)
        ->set('custom_attributes.active_ingredient', 'Ibuprofen')
        ->set('custom_attributes.dosage_form', 'bukan-pilihan')
        ->call('save')
        ->assertHasErrors(['custom_attributes.dosage_form'])
        ->set('custom_attributes.dosage_form', 'tablet')
        ->set('drug_class', DrugClass::Prescription->value)
        ->call('save')
        ->assertHasNoErrors();

    $product->refresh();
    expect(collect($product->custom_attributes)->sortKeys()->all())->toBe(['active_ingredient' => 'Ibuprofen', 'dosage_form' => 'tablet', 'legacy_key' => 'tetap'])
        ->and($product->requires_prescription)->toBeTrue()
        ->and($product->attributes_search)->toBe('Ibuprofen');
});

test('the onboarding API lists capabilities and applies the ticked ones', function () {
    $owner = apiActingAs('superadmin');
    $owner->tenant->forceFill(['onboarded_at' => null, 'store_type' => null])->save();
    Sale::query()->delete();

    $this->getJson('/api/v1/onboarding/presets')
        ->assertOk()
        ->assertJsonPath('data.7.key', 'apotek')
        ->assertJsonPath('data.7.capabilities.3.key', 'business.prescription')
        ->assertJsonPath('data.7.capabilities.3.default_on', true);

    $this->postJson('/api/v1/onboarding/apply', ['store_type' => 'apotek', 'include_sample_products' => false, 'capabilities' => ['business.multi-unit']])
        ->assertOk()
        ->assertJsonPath('data.capabilities', ['business.multi-unit']);

    $this->getJson('/api/v1/meta')->assertOk()->assertJsonPath('data.product_attributes', []);
});
