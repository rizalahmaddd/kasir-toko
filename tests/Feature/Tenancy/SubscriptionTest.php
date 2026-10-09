<?php

use App\Livewire\Settings\RolesAndPermissions;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SubscriptionExpiringNotification;
use App\Support\CurrentTenant;
use App\Support\SaasPlans;
use App\Support\SaasSettings;
use Illuminate\Support\Facades\Notification;
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

it('tells a blocked shop on the API how to renew', function () {
    app(CurrentTenant::class)->run(null, function () {
        Setting::put(SaasSettings::SUPPORT_CONTACT, '0812-0000-1111');
        Setting::put(SaasSettings::PAYMENT_INSTRUCTIONS, 'Transfer BCA 123');
    });
    SaasPlans::save(['basic' => ['label' => 'Basic', 'price' => 99000, 'max_users' => 3, 'max_products' => 1000]]);
    $user = apiActingAs('admin');

    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.tenant.renewal', null);

    $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
    $user->unsetRelation('tenant');
    $renewal = [
        'contact' => '0812-0000-1111',
        'payment_instructions' => 'Transfer BCA 123',
        // Paket bawaan berbayar (Pro, Lifetime) selalu ikut ditawarkan walau tidak ada di daftar yang disimpan admin.
        'plans' => [
            ['key' => 'basic', 'label' => 'Basic', 'price' => 99000],
            ['key' => 'pro', 'label' => SaasPlans::DEFAULT_PLANS['pro']['label'], 'price' => SaasPlans::DEFAULT_PLANS['pro']['price']],
            ['key' => 'lifetime', 'label' => SaasPlans::DEFAULT_PLANS['lifetime']['label'], 'price' => SaasPlans::DEFAULT_PLANS['lifetime']['price']],
        ],
    ];

    $this->getJson('/api/v1/master-data/products')->assertStatus(402)->assertJsonPath('renewal', $renewal);
    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.tenant.renewal', $renewal);
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

it('allows access during grace period and blocks only after grace ends', function () {
    config(['saas.grace_days' => 3]);
    $this->tenant->update([
        'plan' => 'basic',
        'subscription_ends_at' => now()->subDay(),
    ]);

    expect($this->tenant->isInGracePeriod())->toBeTrue()
        ->and($this->tenant->blockedReason())->toBeNull();

    // Setelah grace period berakhir (4 hari lewat)
    $this->tenant->update([
        'subscription_ends_at' => now()->subDays(4),
    ]);

    expect($this->tenant->isInGracePeriod())->toBeFalse()
        ->and($this->tenant->blockedReason())->toBe('subscription_expired');
});

it('sends expiring notifications via check-expirations command', function () {
    Notification::fake();

    $this->tenant->update([
        'name' => 'Toko Harapan',
        'plan' => 'basic',
        'subscription_ends_at' => now()->addDays(3)->endOfDay(),
    ]);

    $owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $owner->assignRole(seededRole('superadmin'));

    $this->artisan('saas:check-expirations')
        ->assertSuccessful();

    Notification::assertSentTo(
        $owner,
        SubscriptionExpiringNotification::class,
        fn ($notification) => $notification->stage === 'd3'
    );
});
