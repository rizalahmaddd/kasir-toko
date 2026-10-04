<?php

use App\Livewire\Reports\ActivityLogReport;
use App\Models\Customer;
use Livewire\Livewire;

test('guests cannot view the activity log report', function () {
    $this->get(route('reports.activity-log'))->assertRedirect('/login');
});

test('roles without the activity permission cannot view the activity log report', function () {
    actingAsRole('staff');

    $this->get(route('reports.activity-log'))->assertForbidden();
});

test('admin sees recorded data changes in the activity log', function () {
    $admin = actingAsAdmin();
    Customer::factory()->create(['name' => 'PT Log Aktivitas']);

    Livewire::test(ActivityLogReport::class)
        ->assertOk()
        ->assertSee('Perubahan Data')
        ->assertSee('PT Log Aktivitas')
        ->assertSee($admin->name);
});

test('filtering by log name narrows the results', function () {
    actingAsAdmin();
    Customer::factory()->create();

    Livewire::test(ActivityLogReport::class)
        ->set('logName', 'roles')
        ->assertOk()
        ->assertSee('Tidak ada aktivitas pada filter ini');
});

test('settings activity can be filtered and shows a readable category', function () {
    $admin = actingAsAdmin();
    activity('settings')->causedBy($admin)->log('Branding aplikasi diubah: Contoh.');

    Livewire::test(ActivityLogReport::class)
        ->set('logName', 'settings')
        ->assertSee('Branding aplikasi diubah: Contoh.')
        ->assertSee('Pengaturan');
});
