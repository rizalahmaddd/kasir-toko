<?php

use App\Livewire\Platform\Admins;
use App\Livewire\Platform\ServiceSettings;
use App\Livewire\Platform\Tenants;
use App\Livewire\Platform\TenantShow;
use App\Models\Tenant;
use App\Models\TenantSubscriptionLog;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\SaasSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Livewire\Volt\Volt;

function signupPayload(): array
{
    return [
        'shop_name' => 'Toko Baru Jaya',
        'name' => 'Pemilik Baru',
        'username' => 'pemilikbaru',
        'email' => 'pemilik@baru.test',
        'password' => 'rahasia-kuat-123',
        'password_confirmation' => 'rahasia-kuat-123',
        'device_name' => 'Pixel',
    ];
}

it('shows the platform dashboard with shops whose access ends soon', function () {
    $endingSoon = Tenant::factory()->trial(daysLeft: 3)->create(['name' => 'Toko Hampir Habis']);
    Tenant::factory()->trial(daysLeft: 30)->create(['name' => 'Toko Masih Lama']);
    actingAsPlatformAdmin();

    $this->get(route('platform.dashboard'))
        ->assertOk()
        ->assertSee($endingSoon->name)
        ->assertDontSee('Toko Masih Lama');
});

it('keeps shop accounts out of the platform pages', function () {
    actingAsSuperAdmin();

    $this->get(route('platform.dashboard'))->assertForbidden();
    $this->get(route('platform.tenants.show', $this->tenant))->assertForbidden();
    $this->get(route('platform.admins'))->assertForbidden();
    $this->get(route('platform.settings'))->assertForbidden();
});

it('records plan changes and extensions with their payment in the subscription history', function () {
    $tenant = Tenant::factory()->trial(daysLeft: 2)->create();
    $admin = actingAsPlatformAdmin();

    Livewire::test(Tenants::class)
        ->call('openEditModal', $tenant->id)
        ->set('plan', 'free')
        ->set('access_ends_at', now()->addDays(30)->toDateString())
        ->set('amount', 'Rp99.000')
        ->set('note', 'Transfer BCA')
        ->call('save')
        ->assertHasNoErrors()
        ->call('extend', $tenant->id);

    $logs = TenantSubscriptionLog::query()->where('tenant_id', $tenant->id)->oldest('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->only(['action', 'from_plan', 'to_plan', 'amount', 'note', 'user_id']))->toBe([
            'action' => TenantSubscriptionLog::ACTION_UPDATE, 'from_plan' => 'trial', 'to_plan' => 'free', 'amount' => 99000, 'note' => 'Transfer BCA', 'user_id' => $admin->id,
        ])
        ->and($logs[1]->action)->toBe(TenantSubscriptionLog::ACTION_EXTEND)
        ->and($tenant->fresh()->subscription_ends_at->isSameDay(now()->addDays(60)))->toBeTrue();
});

it('does not log an edit that changes nothing', function () {
    $tenant = Tenant::factory()->create(['plan' => 'pro']);
    actingAsPlatformAdmin();

    Livewire::test(Tenants::class)->call('openEditModal', $tenant->id)->call('save')->assertHasNoErrors();

    expect(TenantSubscriptionLog::query()->count())->toBe(0);
});

it('shows a shop with its owner, usage and history', function () {
    $tenant = provisionedShop('Kopi Pagi');
    actingAsPlatformAdmin();

    $this->get(route('platform.tenants.show', $tenant))
        ->assertOk()
        ->assertSee('Kopi Pagi')
        ->assertSee('Pemilik Kopi Pagi')
        ->assertSee('Riwayat Langganan');
});

it('suspends and reactivates a shop from its detail page', function () {
    $tenant = provisionedShop();
    actingAsPlatformAdmin();

    $page = Livewire::test(TenantShow::class, ['tenant' => $tenant])->call('toggleStatus');
    expect($tenant->fresh()->blockedReason())->toBe('tenant_suspended');

    $page->call('toggleStatus');
    expect($tenant->fresh()->blockedReason())->toBeNull()
        ->and(TenantSubscriptionLog::query()->where('action', TenantSubscriptionLog::ACTION_STATUS)->count())->toBe(2);
});

it('extends a shop by the chosen period from its detail page', function () {
    $tenant = Tenant::factory()->trial(daysLeft: 0)->create();
    actingAsPlatformAdmin();

    Livewire::test(TenantShow::class, ['tenant' => $tenant])
        ->set('extendDays', '90')
        ->set('amount', '250000')
        ->call('extend')
        ->assertHasNoErrors();

    expect($tenant->fresh()->trial_ends_at->isSameDay(now()->addDays(90)))->toBeTrue()
        ->and(TenantSubscriptionLog::query()->sole()->amount)->toBe(250000);
});

it('resets the shop owner password and signs out their devices', function () {
    $tenant = provisionedShop();
    $owner = $tenant->owner();
    $owner->createToken('android');
    actingAsPlatformAdmin();

    Livewire::test(TenantShow::class, ['tenant' => $tenant])
        ->set('newPassword', 'rahasia-baru-456')
        ->call('resetOwnerPassword')
        ->assertHasNoErrors();

    $owner->refresh();

    expect(Hash::check('rahasia-baru-456', $owner->password))->toBeTrue()
        ->and($owner->tokens()->count())->toBe(0);
});

it('adds a platform admin and revokes access without touching its own account', function () {
    $me = actingAsPlatformAdmin();

    Livewire::test(Admins::class)
        ->call('openCreateModal')
        ->set('name', 'Pengelola Dua')
        ->set('username', 'pengelola2')
        ->set('email', 'dua@layanan.test')
        ->set('password', 'rahasia-kuat-123')
        ->call('save')
        ->assertHasNoErrors();

    $second = User::query()->withoutGlobalScopes()->where('email', 'dua@layanan.test')->sole();

    Livewire::test(Admins::class)
        ->call('toggleAccess', $second->id)
        ->call('toggleAccess', $me->id);

    expect($second->fresh()->isPlatformAdmin())->toBeFalse()
        ->and($me->fresh()->isPlatformAdmin())->toBeTrue();
});

it('refuses to edit shop users from the platform admin list', function () {
    $shopUser = User::factory()->create();
    actingAsPlatformAdmin();

    Livewire::test(Admins::class)->call('openEditModal', $shopUser->id)->assertNotFound();
    Livewire::test(Admins::class)->call('toggleAccess', $shopUser->id)->assertNotFound();
});

it('closes registration on the web and the API', function () {
    actingAsPlatformAdmin();
    Livewire::test(ServiceSettings::class)->set('registrationOpen', false)->set('trialDays', '7')->call('save')->assertHasNoErrors();
    auth()->logout();
    app(CurrentTenant::class)->set(null);

    expect(SaasSettings::registrationOpen())->toBeFalse();

    $this->get(route('register'))->assertSee('Pendaftaran toko baru sedang ditutup');
    Volt::test('pages.auth.register')->set(Arr::except(signupPayload(), 'device_name'))->call('register')->assertForbidden();
    $this->postJson('/api/v1/auth/register', signupPayload())->assertForbidden();

    expect(Tenant::query()->where('name', 'Toko Baru Jaya')->exists())->toBeFalse();
});

it('starts new shops with the trial length set in the platform settings', function () {
    actingAsPlatformAdmin();
    Livewire::test(ServiceSettings::class)->set('trialDays', '7')->call('save')->assertHasNoErrors();
    auth()->logout();
    app(CurrentTenant::class)->set(null);

    $this->postJson('/api/v1/auth/register', signupPayload())->assertCreated();

    expect(Tenant::query()->where('name', 'Toko Baru Jaya')->sole()->trial_ends_at->isSameDay(now()->addDays(7)))->toBeTrue();
});
