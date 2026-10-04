<?php

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Branding;
use App\Support\CurrentTenant;

test('app:install creates the first superadmin, roles, and branding', function () {
    $this->artisan('app:install', [
        '--app-name' => 'Aplikasi Baru',
        '--company' => 'PT Baru',
        '--name' => 'Pemilik',
        '--email' => 'pemilik@example.test',
        '--username' => 'Pemilik',
        '--password' => 'rahasia123',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $tenant = Tenant::query()->where('name', 'PT Baru')->sole();
    app(CurrentTenant::class)->set($tenant);
    $user = User::sole();

    expect($user->username)->toBe('pemilik')
        ->and($user->isSuperAdmin())->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(Branding::appName())->toBe('Aplikasi Baru')
        ->and(Branding::companyName())->toBe('PT Baru')
        ->and(Customer::count())->toBe(0)
        ->and($user->tenant_id)->toBe($tenant->id);

    app(CurrentTenant::class)->set(null);
    expect(Branding::appName())->toBe('Aplikasi Baru');
});

test('app:install stops without creating an account when the input is invalid', function () {
    $this->artisan('app:install', [
        '--app-name' => 'Aplikasi Baru',
        '--company' => 'PT Baru',
        '--name' => 'Pemilik',
        '--email' => 'bukan-email',
        '--username' => 'pemilik',
        '--password' => 'pendek',
        '--no-interaction' => true,
    ])->assertFailed();

    expect(User::withoutGlobalScopes()->count())->toBe(0)
        ->and(Tenant::query()->where('name', 'PT Baru')->exists())->toBeFalse();
});

test('app:install --demo also seeds demo customers', function () {
    $this->artisan('app:install', [
        '--app-name' => 'Aplikasi Baru',
        '--company' => 'PT Baru',
        '--name' => 'Pemilik',
        '--email' => 'pemilik@example.test',
        '--username' => 'pemilik',
        '--password' => 'rahasia123',
        '--demo' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $tenant = Tenant::query()->where('name', 'PT Baru')->sole();

    expect(Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBeGreaterThan(0)
        ->and(Customer::count())->toBe(0);
});
