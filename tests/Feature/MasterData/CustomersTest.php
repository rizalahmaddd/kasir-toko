<?php

use App\Livewire\MasterData\Customers;
use App\Models\Customer;
use App\Models\Sale;
use Livewire\Livewire;

test('guests cannot view the customers page', function () {
    $this->get(route('master-data.customers'))->assertRedirect('/login');
});

test('admin operasional can create, edit, and delete a customer', function () {
    actingAsAdmin();

    Livewire::test(Customers::class)
        ->call('openCreateModal')
        ->set('code', 'CUST-0001')
        ->set('name', 'PT Sinar Nusantara')
        ->set('type', 'Pabrik Rokok')
        ->call('save')
        ->assertHasNoErrors();

    $customer = Customer::where('code', 'CUST-0001')->firstOrFail();

    Livewire::test(Customers::class)
        ->call('openEditModal', $customer->id)
        ->set('payment_term_days', '45')
        ->call('save')
        ->assertHasNoErrors();

    expect($customer->fresh()->payment_term_days)->toBe(45);

    Livewire::test(Customers::class)
        ->call('confirmDelete', $customer->id)
        ->call('delete');

    $this->assertSoftDeleted('customers', ['id' => $customer->id]);
});

test('customer code must be unique', function () {
    actingAsAdmin();
    Customer::factory()->create(['code' => 'CUST-0001']);

    Livewire::test(Customers::class)
        ->call('openCreateModal')
        ->set('code', 'CUST-0001')
        ->set('name', 'Pelanggan Lain')
        ->call('save')
        ->assertHasErrors(['code']);
});

test('read-only roles cannot manage customers', function () {
    actingAsRole('staff');

    Livewire::test(Customers::class)
        ->call('openCreateModal')
        ->assertForbidden();
});

test('a customer with unpaid credit cannot be deleted', function () {
    actingAsAdmin();
    $customer = Customer::factory()->create();
    Sale::factory()->create(['customer_id' => $customer->id, 'total' => 50000, 'paid_amount' => 20000, 'due_amount' => 30000]);

    Livewire::test(Customers::class)
        ->call('confirmDelete', $customer->id)
        ->call('delete')
        ->assertDispatched('notify', type: 'error');

    expect($customer->fresh()->trashed())->toBeFalse();
});
