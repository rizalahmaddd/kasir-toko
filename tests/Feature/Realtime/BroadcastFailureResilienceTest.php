<?php

use App\Models\Customer;

/**
 * Menyimpan data tidak boleh gagal hanya karena Reverb tidak jalan: semua event realtime wajib
 * ShouldRescue (App\Events\Concerns\BroadcastsToDashboard). Test ini menyalakan driver "reverb"
 * sungguhan (bukan "null" bawaan phpunit.xml) supaya koneksi ke server yang mati benar-benar dicoba.
 */
test('saving a customer succeeds even when the broadcast server is unreachable', function () {
    config(['broadcasting.default' => 'reverb']);
    actingAsAdmin();

    $customer = Customer::factory()->create();

    expect($customer->exists)->toBeTrue();
    $this->assertDatabaseHas('customers', ['id' => $customer->id]);
});
