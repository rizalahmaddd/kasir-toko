<?php

use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Testing\TestResponse;

/**
 * Livewire::test() melewati middleware, jadi aksi dikirim seperti browser: ambil snapshot dari
 * halaman lalu POST ke endpoint update Livewire. Hanya cara ini yang menjalankan persistent middleware.
 */
function callLivewireAction(TestResponse $page, string $component, string $method, array $params = []): TestResponse
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);

    $snapshot = collect($matches[1])
        ->map(fn (string $raw) => htmlspecialchars_decode($raw, ENT_QUOTES))
        ->first(fn (string $json) => (json_decode($json, true)['memo']['name'] ?? null) === $component);

    expect($snapshot)->not->toBeNull("Komponen {$component} tidak ada di halaman.");

    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(route('default.livewire.update'), [
        'components' => [['snapshot' => $snapshot, 'updates' => (object) [], 'calls' => [['path' => '', 'method' => $method, 'params' => $params]]]],
    ]);
}

it('rejects actions on a page that was opened before the shop got suspended', function () {
    actingAsAdmin();
    $page = $this->get(route('master-data.categories'))->assertOk();

    $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

    callLivewireAction($page, 'master-data.categories', 'openCreateModal')->assertStatus(402);
});

it('rejects saving data once the subscription ran out mid-session', function () {
    $this->tenant->update(['plan' => 'basic', 'subscription_ends_at' => now()->addHour()]);
    actingAsAdmin();
    $page = $this->get(route('master-data.categories'))->assertOk();

    $this->travel(2)->hours();

    callLivewireAction($page, 'master-data.categories', 'save')->assertStatus(402);
    expect(Category::query()->count())->toBe(0);
});

it('still runs actions for an active shop', function () {
    actingAsAdmin();
    $page = $this->get(route('master-data.categories'))->assertOk();

    callLivewireAction($page, 'master-data.categories', 'openCreateModal')->assertOk();
});

it('lets a blocked shop sign out from the subscription page', function () {
    $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
    actingAsAdmin();
    $page = $this->get(route('subscription.inactive'))->assertOk();

    callLivewireAction($page, 'pages.subscription-inactive', 'logout')->assertOk();
    $this->assertGuest();
});

it('runs platform panel actions for platform admins', function () {
    $tenant = Tenant::factory()->trial(daysLeft: 1)->create();
    actingAsPlatformAdmin();
    $page = $this->get(route('platform.tenants'))->assertOk();

    callLivewireAction($page, 'platform.tenants', 'extend', [$tenant->id])->assertOk();

    expect($tenant->fresh()->trial_ends_at->isSameDay(now()->addDays(31)))->toBeTrue();
});
