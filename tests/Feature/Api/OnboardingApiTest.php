<?php

use App\Enums\StoreType;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Support\StorePresets;

beforeEach(function () {
    $this->tenant->forceFill(['onboarded_at' => null])->save();
});

it('returns 401 without a token', function () {
    $this->getJson('/api/v1/onboarding/presets')->assertUnauthorized();
    $this->postJson('/api/v1/onboarding/apply', ['store_type' => 'warung'])->assertUnauthorized();
    $this->postJson('/api/v1/onboarding/skip')->assertUnauthorized();
});

it('lists every store preset with its categories and settings', function () {
    apiActingAs('kasir');

    $this->getJson('/api/v1/onboarding/presets')
        ->assertOk()
        ->assertJsonCount(count(StoreType::cases()), 'data')
        ->assertJsonPath('data.2.key', 'kafe')
        ->assertJsonPath('data.2.label', 'Kafe / Coffee Shop')
        ->assertJsonPath('data.2.categories', StorePresets::categories(StoreType::Cafe))
        ->assertJsonPath('data.2.sample_product_count', StorePresets::sampleProductCount(StoreType::Cafe))
        ->assertJsonPath('data.2.settings.tax_label', 'PB1')
        ->assertJsonPath('data.2.disabled_features', ['pos.receivables']);
});

it('reports the onboarding status in auth/me', function () {
    apiActingAs('superadmin');

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.tenant.onboarded', false)
        ->assertJsonPath('data.tenant.store_type', null);
});

it('applies a preset for the owner', function () {
    apiActingAs('superadmin');

    $this->postJson('/api/v1/onboarding/apply', ['store_type' => 'apotek', 'include_sample_products' => true])
        ->assertOk()
        ->assertJsonPath('data.categories_created', count(StorePresets::categories(StoreType::Pharmacy)))
        ->assertJsonPath('data.products_created', StorePresets::sampleProductCount(StoreType::Pharmacy))
        ->assertJsonPath('data.tenant.onboarded', true)
        ->assertJsonPath('data.tenant.store_type', 'apotek');

    expect(Product::query()->count())->toBe(StorePresets::sampleProductCount(StoreType::Pharmacy));
});

it('includes sample products unless the client opts out', function () {
    apiActingAs('superadmin');

    $this->postJson('/api/v1/onboarding/apply', ['store_type' => 'bangunan', 'include_sample_products' => false])->assertOk();

    expect(Category::query()->count())->toBe(count(StorePresets::categories(StoreType::BuildingSupply)))
        ->and(Product::query()->count())->toBe(0);
});

it('returns 403 when a non-owner applies or skips', function () {
    apiActingAs('admin');

    $this->postJson('/api/v1/onboarding/apply', ['store_type' => 'warung'])->assertForbidden();
    $this->postJson('/api/v1/onboarding/skip')->assertForbidden();

    expect($this->tenant->refresh()->isOnboarded())->toBeFalse();
});

it('returns 422 for a missing or unknown store type', function (array $payload) {
    apiActingAs('superadmin');

    $this->postJson('/api/v1/onboarding/apply', $payload)->assertJsonValidationErrors('store_type');
})->with([
    'missing' => [[]],
    'unknown' => [['store_type' => 'kapal-pesiar']],
]);

it('returns 422 when re-applying after the store has sales', function () {
    apiActingAs('superadmin');
    $this->postJson('/api/v1/onboarding/apply', ['store_type' => 'warung'])->assertOk();
    Sale::factory()->create();

    $this->postJson('/api/v1/onboarding/apply', ['store_type' => 'kafe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['store_type' => 'Preset tidak bisa diterapkan lagi karena toko ini sudah punya transaksi penjualan.']);

    expect($this->tenant->refresh()->store_type)->toBe(StoreType::Warung);
});

it('skips onboarding for the owner', function () {
    apiActingAs('superadmin');

    $this->postJson('/api/v1/onboarding/skip')
        ->assertOk()
        ->assertJsonPath('data.onboarded', true)
        ->assertJsonPath('data.store_type', null);

    expect(Category::query()->count())->toBe(0);
});
