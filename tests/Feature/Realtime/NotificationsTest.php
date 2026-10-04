<?php

use App\Models\Customer;
use App\Models\User;
use App\Notifications\CustomerCreatedNotification;
use Illuminate\Support\Facades\Notification;

test('a new customer notifies other master data managers, not the creator or read-only roles', function () {
    Notification::fake();

    $otherAdmin = User::factory()->create();
    $otherAdmin->assignRole(seededRole('admin'));
    $staff = User::factory()->create();
    $staff->assignRole(seededRole('staff'));
    $creator = actingAsAdmin();

    $customer = Customer::factory()->create(['name' => 'PT Notifikasi Uji']);

    Notification::assertSentTo($otherAdmin, CustomerCreatedNotification::class,
        fn (CustomerCreatedNotification $notification) => $notification->customer->is($customer) && $notification->createdBy === $creator->name);
    Notification::assertNotSentTo([$creator, $staff], CustomerCreatedNotification::class);
});

test('the notification links to the customer detail page', function () {
    $customer = Customer::factory()->create(['name' => 'PT Tautan', 'code' => 'CUST-LINK']);

    $data = (new CustomerCreatedNotification($customer, 'Admin Demo'))->toArray(User::factory()->make());

    expect($data['url'])->toBe(route('master-data.customers.show', $customer))
        ->and($data['message'])->toContain('PT Tautan (CUST-LINK)')
        ->and($data['message'])->toContain('Admin Demo');
});
