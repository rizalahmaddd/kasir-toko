<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

function registrationPayload(array $overrides = []): array
{
    return [
        'shop_name' => 'Toko Baru Jaya',
        'name' => 'Pemilik Baru',
        'username' => 'pemilikbaru',
        'email' => 'pemilik@baru.test',
        'phone' => '081234500001',
        'password' => 'rahasia-kuat-123',
        'password_confirmation' => 'rahasia-kuat-123',
        ...$overrides,
    ];
}

it('registers a new shop on a trial and returns a token for its owner', function () {
    $response = $this->postJson('/api/v1/auth/register', registrationPayload(['device_name' => 'Pixel']))
        ->assertCreated()
        ->assertJsonPath('data.user.username', 'pemilikbaru')
        ->assertJsonPath('data.user.is_superadmin', true)
        ->assertJsonPath('data.user.tenant.name', 'Toko Baru Jaya')
        ->assertJsonPath('data.user.tenant.plan', 'trial')
        ->assertJsonPath('data.user.tenant.blocked_reason', null);

    $tenant = Tenant::query()->where('name', 'Toko Baru Jaya')->sole();

    expect($tenant->trial_ends_at->isSameDay(now()->addDays(config('saas.trial_days'))))->toBeTrue()
        ->and(Role::query()->where('tenant_id', $tenant->id)->pluck('name')->sort()->values()->all())->toBe(['admin', 'kasir', 'staff', 'superadmin'])
        ->and($response->json('data.token'))->toBeString();

    $this->withToken($response->json('data.token'))->getJson('/api/v1/master-data/products')->assertOk();
});

it('rejects a registration that reuses another account\'s email', function () {
    platformAdmin()->forceFill(['email' => 'pemilik@baru.test'])->save();

    $this->postJson('/api/v1/auth/register', registrationPayload(['device_name' => 'Pixel']))
        ->assertJsonValidationErrors('email');

    expect(Tenant::query()->where('name', 'Toko Baru Jaya')->exists())->toBeFalse();
});

it('registers from the web page and signs the owner in', function () {
    app(CurrentTenant::class)->set(null);

    Volt::test('pages.auth.register')
        ->set(registrationPayload())
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $owner = User::withoutGlobalScopes()->where('email', 'pemilik@baru.test')->sole();

    expect(auth()->id())->toBe($owner->id)
        ->and($owner->tenant->name)->toBe('Toko Baru Jaya');
});

it('links to the registration page from the login page', function () {
    $this->get(route('login'))->assertOk()->assertSee(route('register'));
    $this->get(route('register'))->assertOk()->assertSee('Daftar Toko Baru');
});
