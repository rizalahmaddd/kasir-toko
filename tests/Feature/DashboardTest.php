<?php

use App\Models\Customer;
use App\Models\User;

test('root redirects to the dashboard', function () {
    $this->get('/')->assertRedirect('/dashboard');
});

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('a user without a role sees an explanation instead of data', function () {
    Customer::factory()->count(3)->create();
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertSee('Akun Anda belum punya peran')
        ->assertDontSee('Aktivitas Terbaru');
});

test('staff sees the customer count but not the activity feed', function () {
    Customer::factory()->count(3)->create();
    Customer::factory()->create(['is_active' => false]);
    actingAsRole('staff');

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Pelanggan')
        ->assertSee('3 aktif')
        ->assertDontSee('Aktivitas Terbaru');
});

test('admin sees the recent activity feed', function () {
    actingAsAdmin();
    Customer::factory()->create(['name' => 'PT Contoh Audit']);

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Aktivitas Terbaru')
        ->assertSee('PT Contoh Audit');
});
