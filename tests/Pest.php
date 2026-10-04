<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\CurrentTenant;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Login sebagai user ber-role tertentu beserta izin bawaannya, supaya tiap test tidak menulis
 * ulang setup role/login.
 */
function actingAsRole(string $role): User
{
    test()->useDefaultTenant();
    $user = User::factory()->create();
    $user->assignRole(seededRole($role));
    test()->actingAs($user);

    return $user;
}

/**
 * Peran beserta izin bawaannya dari PermissionSeeder, seperti hasil seeding di aplikasi nyata.
 * Superadmin dilewati: aksesnya lewat Gate::before, bukan daftar izin.
 */
function seededRole(string $name): Role
{
    $role = Role::findOrCreate($name, 'web');
    $permissions = PermissionSeeder::DEFAULT_ROLE_PERMISSIONS[$name] ?? [];

    if ($permissions !== ['*'] && $permissions !== []) {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $role->givePermissionTo($permissions);
    }

    return $role;
}

/**
 * Same as actingAsRole(), but authenticated through a Sanctum token for the mobile API.
 */
function apiActingAs(string $role): User
{
    test()->useDefaultTenant();
    $user = User::factory()->create();
    $user->assignRole(seededRole($role));
    Sanctum::actingAs($user);

    return $user;
}

function actingAsAdmin(): User
{
    return actingAsRole('admin');
}

function actingAsSuperAdmin(): User
{
    return actingAsRole('superadmin');
}

/**
 * Pengelola layanan SaaS: tanpa toko, hanya boleh membuka panel Platform.
 */
function platformAdmin(): User
{
    return app(CurrentTenant::class)->run(null, function () {
        $user = User::factory()->create();
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    });
}

function actingAsPlatformAdmin(): User
{
    $user = platformAdmin();
    test()->actingAs($user);

    return $user;
}

/**
 * Seluruh data demo (DatabaseSeeder) beserta tenant-nya dijadikan tenant aktif, supaya user dari
 * actingAsRole() masuk ke toko yang sama dengan data demo.
 */
function seedDemoTenant(): Tenant
{
    test()->seed(DatabaseSeeder::class);

    $tenant = Tenant::query()->where('slug', 'toko-demo')->sole();
    app(CurrentTenant::class)->set($tenant);

    return $tenant;
}

/**
 * Toko lengkap seperti hasil pendaftaran (peran bawaan + akun pemilik), tanpa mengubah tenant aktif tes.
 */
function provisionedShop(string $name = 'Toko Pelanggan'): Tenant
{
    $previous = app(CurrentTenant::class)->id();

    ['tenant' => $tenant] = app(TenantProvisioner::class)->provision($name, [
        'name' => 'Pemilik '.$name,
        'username' => 'pemilik'.Str::lower(Str::random(5)),
        'email' => Str::lower(Str::random(8)).'@toko.test',
        'password' => 'rahasia-lama-123',
    ]);

    app(CurrentTenant::class)->set($previous);

    return $tenant;
}
