<?php

use App\Livewire\Platform\TenantShow;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

test('platform admin can impersonate a tenant owner and return back', function () {
    $admin = actingAsPlatformAdmin();

    $tenant = Tenant::factory()->create(['name' => 'Toko Barokah']);
    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Budi Pemilik',
    ]);
    app(CurrentTenant::class)->run($tenant, fn () => $owner->assignRole(seededRole('superadmin')));

    // Impersonate
    Livewire::test(TenantShow::class, ['tenant' => $tenant])
        ->call('impersonate')
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($owner->id);
    expect(session('impersonator_id'))->toBe($admin->id);

    // Verify activity logged
    $startLog = Activity::where('event', 'impersonate_start')->latest('id')->first();
    expect($startLog)->not->toBeNull();
    expect($startLog->description)->toContain('Toko Barokah');

    // Visiting dashboard shows impersonation banner
    $response = $this->get(route('dashboard'));
    $response->assertOk();
    $response->assertSee('Mode Dukungan');
    $response->assertSee('Kembali ke Admin Platform');

    // Leave impersonation
    $leaveResponse = $this->post(route('platform.impersonate.leave'));
    $leaveResponse->assertRedirect(route('platform.tenants'));

    expect(auth()->id())->toBe($admin->id);
    expect(session()->has('impersonator_id'))->toBeFalse();

    // Verify stop activity logged
    $stopLog = Activity::where('event', 'impersonate_stop')->latest('id')->first();
    expect($stopLog)->not->toBeNull();
});

test('regular tenant user cannot leave impersonation without impersonator session', function () {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create(['tenant_id' => $tenant->id]);
    $this->actingAs($owner);

    $this->post(route('platform.impersonate.leave'))
        ->assertForbidden();
});
