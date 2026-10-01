<?php

use App\Models\Customer;
use App\Models\User;
use App\Support\Features;
use Laravel\Sanctum\Sanctum;

it('lets accounts with view access read customers', function () {
    apiActingAs('staff');
    Customer::factory()->create(['name' => 'PT Aktif', 'is_active' => true]);
    Customer::factory()->create(['name' => 'PT Lama', 'is_active' => false]);

    $this->getJson('/api/v1/master-data/customers?is_active=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'PT Aktif')
        ->assertJsonStructure(['links', 'meta' => ['total', 'per_page']]);
});

it('keeps accounts without a role out of master data', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/master-data/customers')->assertForbidden();
});

it('only lets roles with the manage permission change customers', function () {
    $payload = ['code' => 'CUST-API', 'name' => 'PT API', 'payment_term_days' => 30];

    apiActingAs('staff');
    $this->postJson('/api/v1/master-data/customers', $payload)->assertForbidden();

    apiActingAs('admin');
    $this->postJson('/api/v1/master-data/customers', $payload)
        ->assertCreated()
        ->assertJsonPath('data.code', 'CUST-API');

    $this->postJson('/api/v1/master-data/customers', [...$payload, 'name' => 'PT Lain'])
        ->assertJsonValidationErrors('code');
});

it('ignores the record itself when checking unique fields on update', function () {
    apiActingAs('admin');
    $customer = Customer::factory()->create(['code' => 'CUST-SAME']);

    $this->putJson("/api/v1/master-data/customers/{$customer->id}", ['code' => 'CUST-SAME', 'name' => 'Nama Baru', 'payment_term_days' => 0])
        ->assertOk()
        ->assertJsonPath('data.name', 'Nama Baru');
});

it('soft deletes a customer', function () {
    apiActingAs('admin');
    $customer = Customer::factory()->create();

    $this->deleteJson("/api/v1/master-data/customers/{$customer->id}")->assertNoContent();

    $this->assertSoftDeleted($customer);
});

it('returns a friendly 404 for a missing record', function () {
    apiActingAs('admin');

    $this->getJson('/api/v1/master-data/customers/999999')
        ->assertNotFound()
        ->assertJsonPath('message', 'Data tidak ditemukan.');
});

it('closes endpoints of a disabled feature', function () {
    apiActingAs('admin');
    Features::setDisabled(['master-data.customers']);

    $this->getJson('/api/v1/master-data/customers')
        ->assertForbidden()
        ->assertJsonPath('message', 'Fitur ini sedang dinonaktifkan.');
});
