<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

test('auth config endpoint returns disabled status when credentials are not configured', function () {
    Config::set('services.google.client_id', null);
    Config::set('services.apple.bundle_id', null);
    Config::set('services.apple.client_id', null);
    Config::set('services.fonnte.token', null);

    $response = $this->getJson('/api/v1/auth/config');

    $response->assertOk()
        ->assertJson([
            'data' => [
                'google' => ['enabled' => false, 'client_id' => null],
                'apple' => ['enabled' => false, 'bundle_id' => null],
                'whatsapp_otp' => ['enabled' => false],
            ],
        ]);
});

test('auth config endpoint returns enabled status when credentials are configured', function () {
    Config::set('services.google.client_id', 'google-client-id-123.apps.googleusercontent.com');
    Config::set('services.apple.bundle_id', 'com.kasir.pos');
    Config::set('services.fonnte.token', 'test-fonnte-token-123');

    $response = $this->getJson('/api/v1/auth/config');

    $response->assertOk()
        ->assertJson([
            'data' => [
                'google' => ['enabled' => true, 'client_id' => 'google-client-id-123.apps.googleusercontent.com'],
                'apple' => ['enabled' => true, 'bundle_id' => 'com.kasir.pos'],
                'whatsapp_otp' => ['enabled' => true],
            ],
        ]);
});

test('google login provisions new tenant and user when user does not exist', function () {
    Http::fake([
        'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
            'sub' => 'google-uid-999',
            'email' => 'newowner@gmail.com',
            'name' => 'Owner Baru',
            'picture' => 'https://lh3.googleusercontent.com/avatar.jpg',
            'email_verified' => 'true',
        ], 200),
    ]);

    $response = $this->postJson('/api/v1/auth/google', [
        'id_token' => 'valid-mock-id-token',
        'shop_name' => 'Kopi Mantap',
        'device_name' => 'Samsung S22',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'token',
                'user' => ['id', 'name', 'email', 'tenant'],
            ],
        ]);

    $user = User::query()->where('google_id', 'google-uid-999')->first();
    expect($user)->not->toBeNull()
        ->and($user->email)->toBe('newowner@gmail.com')
        ->and($user->name)->toBe('Owner Baru')
        ->and($user->avatar)->toBe('https://lh3.googleusercontent.com/avatar.jpg')
        ->and($user->tenant)->not->toBeNull()
        ->and($user->tenant->name)->toBe('Kopi Mantap')
        ->and($user->hasRole('superadmin'))->toBeTrue();
});

test('google login links account when email already exists', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Toko Lawas',
        'slug' => 'toko-lawas',
        'plan' => 'trial',
    ]);

    $user = app(CurrentTenant::class)->run($tenant, function () {
        return User::query()->create([
            'name' => 'Pemilik Lawas',
            'username' => 'pemiliklawas',
            'email' => 'lawas@gmail.com',
            'password' => bcrypt('secret123'),
        ]);
    });

    Http::fake([
        'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
            'sub' => 'google-uid-777',
            'email' => 'lawas@gmail.com',
            'name' => 'Pemilik Lawas Updated',
            'picture' => 'https://google.com/pic.jpg',
            'email_verified' => 'true',
        ], 200),
    ]);

    $response = $this->postJson('/api/v1/auth/google', [
        'id_token' => 'mock-token',
    ]);

    $response->assertOk();

    $user->refresh();
    expect($user->google_id)->toBe('google-uid-777');
});

test('user can delete their own account', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Toko Dihapus',
        'slug' => 'toko-dihapus',
        'plan' => 'trial',
    ]);

    $user = app(CurrentTenant::class)->run($tenant, function () {
        return User::query()->create([
            'name' => 'User Dihapus',
            'username' => 'userhapus',
            'email' => 'hapus@gmail.com',
            'password' => bcrypt('secret123'),
        ]);
    });

    Sanctum::actingAs($user);

    $response = $this->deleteJson('/api/v1/auth/account');

    $response->assertNoContent();

    expect(User::find($user->id))->toBeNull();
});
