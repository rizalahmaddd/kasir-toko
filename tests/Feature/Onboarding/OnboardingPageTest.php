<?php

use App\Enums\StoreType;
use App\Models\Category;
use App\Models\Sale;
use App\Models\Tenant;
use App\Services\StorePresetApplier;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->tenant->forceFill(['onboarded_at' => null])->save();
});

it('redirects the owner of a store that is not set up yet to onboarding', function () {
    actingAsSuperAdmin();

    $this->get(route('dashboard'))->assertRedirect(route('onboarding'));
    $this->get(route('master-data.products'))->assertRedirect(route('onboarding'));
    $this->get(route('onboarding'))->assertOk()->assertSee('Toko Anda jenis apa?')->assertSee('Lewati, mulai dari kosong');
});

it('does not redirect cashiers or other roles', function (string $role) {
    actingAsRole($role);

    $this->get(route('dashboard'))->assertOk();
})->with(['kasir', 'admin']);

it('forbids the onboarding page to roles other than the owner', function () {
    actingAsRole('admin');

    $this->get(route('onboarding'))->assertForbidden();
});

it('does not redirect the owner once the store is onboarded', function () {
    $this->tenant->forceFill(['onboarded_at' => now()])->save();
    actingAsSuperAdmin();

    $this->get(route('dashboard'))->assertOk();
});

it('does not redirect platform admins', function () {
    actingAsPlatformAdmin();

    $this->get(route('platform.tenants'))->assertOk();
});

it('marks stores that existed before the migration as onboarded', function () {
    $migration = require database_path('migrations/2026_10_02_200000_add_onboarding_columns_to_tenants_table.php');
    $migration->down();
    $existingId = DB::table('tenants')->insertGetId(['name' => 'Toko Lama', 'slug' => 'toko-lama', 'created_at' => now(), 'updated_at' => now()]);

    $migration->up();

    expect(Tenant::query()->find($existingId)->isOnboarded())->toBeTrue();
});

it('applies the chosen preset from the wizard and opens the dashboard', function () {
    actingAsSuperAdmin();

    Volt::test('pages.onboarding')
        ->call('choose', 'kafe')
        ->assertSee('Langkah 2 dari 2')
        ->assertSee('PB1 10%')
        ->set('includeSampleProducts', false)
        ->call('apply')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect($this->tenant->refresh()->store_type)->toBe(StoreType::Cafe)
        ->and(Category::query()->pluck('name')->all())->toContain('Kopi');

    $this->get(route('dashboard'))->assertOk();
});

it('ignores an unknown store type', function () {
    actingAsSuperAdmin();

    Volt::test('pages.onboarding')
        ->call('choose', 'kapal-pesiar')
        ->assertSet('storeType', null)
        ->assertSee('Toko Anda jenis apa?');
});

it('skips onboarding without creating data', function () {
    actingAsSuperAdmin();

    Volt::test('pages.onboarding')
        ->call('skip')
        ->assertRedirect(route('dashboard'));

    expect($this->tenant->refresh()->isOnboarded())->toBeTrue()
        ->and(Category::query()->count())->toBe(0);
});

it('lets the owner re-apply a preset from settings until the first sale', function () {
    actingAsSuperAdmin();
    app(StorePresetApplier::class)->apply($this->tenant, StoreType::Warung);

    $this->get(route('settings.company-profile', ['tab' => 'jenis-toko']))
        ->assertOk()
        ->assertSee('Warung / Kelontong')
        ->assertSee(route('onboarding'));

    Sale::factory()->create();

    $this->get(route('settings.company-profile', ['tab' => 'jenis-toko']))
        ->assertOk()
        ->assertDontSee('href="'.route('onboarding').'"', false)
        ->assertSee('sudah punya transaksi penjualan');

    $this->get(route('onboarding'))->assertOk()->assertSee('Preset tidak bisa diterapkan lagi');

    Volt::test('pages.onboarding')
        ->call('choose', 'kafe')
        ->call('apply')
        ->assertHasErrors('storeType');

    expect($this->tenant->refresh()->store_type)->toBe(StoreType::Warung);
});
