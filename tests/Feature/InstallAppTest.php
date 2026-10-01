<?php

use App\Models\Customer;
use App\Models\User;
use App\Support\Branding;

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

    $user = User::sole();

    expect($user->username)->toBe('pemilik')
        ->and($user->isSuperAdmin())->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(Branding::appName())->toBe('Aplikasi Baru')
        ->and(Branding::companyName())->toBe('PT Baru')
        ->and(Customer::count())->toBe(0);
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

    expect(User::count())->toBe(0);
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

    expect(Customer::count())->toBeGreaterThan(0);
});
