<?php

use App\Livewire\Layout\NotificationBell;
use App\Models\Customer;
use App\Models\User;
use App\Support\Features;
use Livewire\Livewire;

/**
 * Klik notifikasi wajib menandainya dibaca DAN pindah ke halaman yang dimaksud, bukan cuma
 * menandai dibaca tanpa aksi lanjutan.
 */
beforeEach(function () {
    $this->recipient = User::factory()->create();
    $this->recipient->assignRole(seededRole('admin'));

    actingAsAdmin();
    $this->customer = Customer::factory()->create();
    $this->notification = $this->recipient->fresh()->unreadNotifications->first();
});

test('opening a notification marks it read and redirects to its page', function () {
    expect($this->notification)->not->toBeNull();

    $this->actingAs($this->recipient);

    Livewire::test(NotificationBell::class)
        ->call('open', $this->notification->id)
        ->assertRedirect(route('master-data.customers.show', $this->customer));

    expect($this->recipient->fresh()->unreadNotifications)->toHaveCount(0);
});

test('opening a notification belonging to someone else does nothing', function () {
    actingAsAdmin();

    Livewire::test(NotificationBell::class)
        ->call('open', $this->notification->id)
        ->assertNoRedirect();

    expect($this->recipient->fresh()->unreadNotifications)->toHaveCount(1);
});

test('notifications pointing at a disabled feature are hidden', function () {
    $this->actingAs($this->recipient);
    Features::setDisabled(['master-data.customers']);

    Livewire::test(NotificationBell::class)
        ->assertViewHas('unreadCount', 0);
});
