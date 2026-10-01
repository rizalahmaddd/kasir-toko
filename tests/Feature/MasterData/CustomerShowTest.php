<?php

use App\Models\Customer;
use App\Models\User;

beforeEach(function () {
    $this->admin = actingAsAdmin();
    $this->customer = Customer::factory()->create(['name' => 'PT Sinar Nusantara']);
});

test('guests cannot view a customer detail page', function () {
    auth()->logout();

    $this->get(route('master-data.customers.show', $this->customer))->assertRedirect('/login');
});

test('the customer detail page shows its profile and change history', function () {
    $this->customer->update(['phone' => '081234567890']);

    $this->get(route('master-data.customers.show', $this->customer))
        ->assertOk()
        ->assertSee($this->customer->name)
        ->assertSee($this->customer->code)
        ->assertSee('Riwayat Perubahan')
        ->assertSee('phone');
});

test('the link to the full activity log is hidden from roles that cannot open it', function () {
    actingAsRole('staff');

    $this->get(route('master-data.customers.show', $this->customer))
        ->assertOk()
        ->assertDontSee('Lihat semua di Log Aktivitas');
});

test('the customer card can be printed', function () {
    $this->get(route('master-data.customers.print', $this->customer))
        ->assertOk()
        ->assertSee('Kartu Pelanggan')
        ->assertSee($this->customer->code);
});

test('users without master data access cannot open the customer pages', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('master-data.customers.show', $this->customer))->assertForbidden();
    $this->get(route('master-data.customers.print', $this->customer))->assertForbidden();
});
