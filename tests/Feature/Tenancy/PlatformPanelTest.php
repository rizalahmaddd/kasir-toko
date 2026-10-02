<?php

use App\Livewire\Platform\Tenants;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

it('keeps platform admins inside the platform panel', function () {
    actingAsPlatformAdmin();

    $this->get(route('dashboard'))->assertRedirect(route('platform.dashboard'));
    $this->get(route('master-data.products'))->assertRedirect(route('platform.dashboard'));
    $this->get(route('platform.tenants'))->assertOk()->assertSee($this->tenant->name);
});

it('refuses the shop API to platform admins', function () {
    Sanctum::actingAs(platformAdmin());

    $this->getJson('/api/v1/master-data/products')->assertForbidden();
});

it('lets platform admins suspend a shop and extend its access', function () {
    $tenant = Tenant::factory()->trial(daysLeft: 2)->create();
    actingAsPlatformAdmin();

    Livewire::test(Tenants::class)
        ->call('extend', $tenant->id)
        ->call('openEditModal', $tenant->id)
        ->set('status', Tenant::STATUS_SUSPENDED)
        ->call('save')
        ->assertHasNoErrors();

    $tenant->refresh();

    expect($tenant->trial_ends_at->isSameDay(now()->addDays(32)))->toBeTrue()
        ->and($tenant->blockedReason())->toBe('tenant_suspended');
});

it('does not let shop superadmins manage tenants', function () {
    actingAsSuperAdmin();

    Livewire::test(Tenants::class)->call('extend', $this->tenant->id)->assertForbidden();
});

it('creates a platform admin from the console', function () {
    $this->artisan('app:platform-admin', [
        '--name' => 'Pengelola',
        '--email' => 'pengelola@layanan.test',
        '--username' => 'pengelola',
        '--password' => 'rahasia-kuat-123',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $admin = User::withoutGlobalScopes()->where('email', 'pengelola@layanan.test')->sole();

    expect($admin->isPlatformAdmin())->toBeTrue();
});
