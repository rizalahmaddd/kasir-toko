<?php

use App\Livewire\Sales\Receivables;
use App\Models\Tenant;
use Livewire\Livewire;

it('includes is_pro and is_trial status in auth me', function () {
    $this->tenant->update(['plan' => Tenant::PLAN_FREE, 'trial_ends_at' => null]);
    apiActingAs('admin');

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.tenant.is_pro', false)
        ->assertJsonPath('data.tenant.is_trial', false)
        ->assertJsonPath('data.tenant.plan', 'free');
});

it('blocks Pro API endpoints when store is on free tier', function () {
    $this->tenant->update(['plan' => Tenant::PLAN_FREE, 'trial_ends_at' => null]);
    apiActingAs('admin');

    $this->getJson('/api/v1/receivables')
        ->assertStatus(403)
        ->assertJsonPath('upgrade_required', true);

    $this->getJson('/api/v1/reports/sales/summary')
        ->assertStatus(403)
        ->assertJsonPath('upgrade_required', true);
});

it('allows essential POS endpoints on free tier', function () {
    $this->tenant->update(['plan' => Tenant::PLAN_FREE, 'trial_ends_at' => null]);
    apiActingAs('admin');

    $this->getJson('/api/v1/pos/products')
        ->assertOk();

    $this->getJson('/api/v1/sales')
        ->assertOk();
});

it('allows Pro API endpoints when store is on active Pro subscription or trial', function () {
    $this->tenant->update(['plan' => Tenant::PLAN_PRO, 'subscription_ends_at' => now()->addMonth()]);
    apiActingAs('admin');

    $this->getJson('/api/v1/receivables')
        ->assertOk();

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.tenant.is_pro', true);
});

it('shows paywall on web receivables for free tier store', function () {
    $this->tenant->update(['plan' => Tenant::PLAN_FREE, 'trial_ends_at' => null]);
    actingAsAdmin();

    Livewire::test(Receivables::class)
        ->assertSee('Eksklusif Paket Pro')
        ->assertSee('Upgrade ke Pro Sekarang');
});

it('allows Pro API endpoints and web features permanently on lifetime plan', function () {
    $this->tenant->update(['plan' => Tenant::PLAN_LIFETIME, 'trial_ends_at' => null, 'subscription_ends_at' => null]);
    apiActingAs('admin');

    $this->getJson('/api/v1/receivables')
        ->assertOk();

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.tenant.is_pro', true)
        ->assertJsonPath('data.tenant.plan', 'lifetime');

    expect($this->tenant->isPro())->toBeTrue()
        ->and($this->tenant->isLifetime())->toBeTrue()
        ->and($this->tenant->accessEndsAt())->toBeNull()
        ->and($this->tenant->hasExpired())->toBeFalse()
        ->and($this->tenant->blockedReason())->toBeNull();
});
