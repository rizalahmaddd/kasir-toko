<?php

use App\Livewire\Settings\RolesAndPermissions;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Livewire\Livewire;

it('locks the API of a suspended shop but still answers who the user is', function () {
    $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
    apiActingAs('admin');

    $this->getJson('/api/v1/master-data/products')
        ->assertStatus(402)
        ->assertJsonPath('reason', 'tenant_suspended');

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.tenant.blocked_reason', 'tenant_suspended');
});

it('locks the API once the trial has ended', function () {
    $this->tenant->update(['plan' => Tenant::PLAN_TRIAL, 'trial_ends_at' => now()->subDay()]);
    apiActingAs('kasir');

    $this->getJson('/api/v1/pos/products')
        ->assertStatus(402)
        ->assertJsonPath('reason', 'trial_expired');
});

it('sends web users of an expired subscription to the subscription page', function () {
    $this->tenant->update(['plan' => 'basic', 'subscription_ends_at' => now()->subDay()]);
    actingAsAdmin();

    $this->get(route('dashboard'))->assertRedirect(route('subscription.inactive'));
    $this->get(route('subscription.inactive'))->assertOk()->assertSee('Langganan toko ini sudah berakhir');
});

it('leaves an active subscription alone', function () {
    $this->tenant->update(['plan' => 'basic', 'subscription_ends_at' => now()->addMonth()]);
    actingAsAdmin();

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('subscription.inactive'))->assertRedirect(route('dashboard', absolute: false));
});

it('stops adding products beyond the plan limit', function () {
    config(['saas.plans.basic.max_products' => 1]);
    $this->tenant->update(['plan' => 'basic']);
    Product::factory()->create();
    apiActingAs('admin');

    $this->postJson('/api/v1/master-data/products', ['name' => 'Teh', 'unit' => 'gelas', 'price' => 4000])
        ->assertJsonValidationErrors('name');
});

it('stops adding users beyond the plan limit', function () {
    config(['saas.plans.basic.max_users' => 1]);
    $this->tenant->update(['plan' => 'basic']);
    seededRole('kasir');
    actingAsSuperAdmin();

    Livewire::test(RolesAndPermissions::class)
        ->set('newUserName', 'Kasir Baru')
        ->set('newUserUsername', 'kasirbaru')
        ->set('newUserEmail', 'kasir@baru.test')
        ->set('newUserPassword', 'rahasia-kuat-123')
        ->set('newUserRoles', ['kasir'])
        ->call('createUser')
        ->assertHasErrors('newUserName');

    expect(app(CurrentTenant::class)->get()->users()->count())->toBe(1);
});
