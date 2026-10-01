<?php

use App\Models\Customer;
use App\Support\Features;
use Laravel\Sanctum\Sanctum;

it('shows only the stats a role may see', function () {
    Customer::factory()->count(2)->create();

    apiActingAs('staff');
    $this->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.stats.0.key', 'customers_active')
        ->assertJsonPath('data.stats.0.count', 2);
});

it('drops stats of disabled features', function () {
    apiActingAs('admin');
    Features::setDisabled(['master-data', 'inventory', 'pos']);

    $this->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.stats', [])
        ->assertJsonPath('data.today', null)
        ->assertJsonPath('data.recent_sales', null);
});

it('lists notifications with a deep link and marks them read', function () {
    $user = apiActingAs('admin');
    actingAsAdmin();
    $customer = Customer::factory()->create();
    Sanctum::actingAs($user);

    $notification = $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('meta.unread_count', 1)
        ->assertJsonPath('data.0.target', ['type' => 'customer', 'id' => $customer->id])
        ->json('data.0');

    $this->postJson("/api/v1/notifications/{$notification['id']}/read")->assertOk();
    $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread_count', 0);
});

it('searches customers the same way as the web search box', function () {
    apiActingAs('staff');
    $customer = Customer::factory()->create(['name' => 'PT Pencarian Unik']);

    $this->getJson('/api/v1/search?q=Pencarian')
        ->assertOk()
        ->assertJsonPath('data.0.group', 'Pelanggan')
        ->assertJsonPath('data.0.items.0.target', ['type' => 'customer', 'id' => $customer->id]);
});

it('returns app configuration in meta', function () {
    apiActingAs('staff');

    $this->getJson('/api/v1/meta')
        ->assertOk()
        ->assertJsonStructure(['data' => ['app' => ['name', 'company_name', 'tagline', 'logo_url'], 'enums']]);
});
