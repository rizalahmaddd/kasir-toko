<?php

use App\Enums\StoreType;
use App\Livewire\Pharmacy\Prescriptions;
use App\Models\Prescription;
use App\Models\Product;
use App\Support\CurrentTenant;
use App\Support\Features;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = actingAsSuperAdmin();
    $this->owner->tenant->forceFill(['store_type' => StoreType::Pharmacy])->save();
    app(CurrentTenant::class)->set($this->owner->tenant->fresh());
    Features::setEnabled(Features::optInFeatures());
});

test('pharmacy pages, prints, and capability screens render', function () {
    $product = Product::factory()->create(['name' => 'Amoxicillin', 'requires_prescription' => true, 'drug_class' => 'keras']);
    $prescription = Prescription::factory()->verified()->create(['patient_name' => 'Ani']);
    $item = $prescription->items()->create(['product_id' => $product->id, 'product_name' => 'Amoxicillin', 'quantity_prescribed' => 10, 'dosage_instructions' => '3 x sehari']);

    $this->get(route('pharmacy.prescriptions'))->assertOk()->assertSee($prescription->number);
    $this->get(route('pharmacy.prescriptions.show', $prescription))->assertOk()->assertSee('Ani');
    $this->get(route('pharmacy.prescriptions.copy', $prescription))->assertOk()->assertSee('nedet 10');
    $this->get(route('pharmacy.prescriptions.labels', ['prescription' => $prescription, 'item' => $item->id]))->assertOk()->assertSee('3 x sehari');
    $this->get(route('pharmacy.report'))->assertOk();
    $this->get(route('inventory.expiry'))->assertOk();
    $this->get(route('master-data.products'))->assertOk()->assertSee('RESEP');
    $this->get(route('pos.cashier'))->assertOk();
    $this->get(route('settings.pos'))->assertOk()->assertSee('Resep obat');
});

test('a prescription is recorded from the web form and the cancel action keeps it', function () {
    $product = Product::factory()->create(['requires_prescription' => true, 'drug_class' => 'keras', 'custom_attributes' => ['default_dosage' => '2 x sehari']]);

    Livewire::test(Prescriptions::class)
        ->call('openCreateModal')
        ->set('doctor_name', 'Budi')
        ->set('patient_name', 'Ani')
        ->set('items.0.product_id', (string) $product->id)
        ->assertSet('items.0.dosage_instructions', '2 x sehari')
        ->set('items.0.quantity', '12')
        ->set('verifyNow', true)
        ->call('save')
        ->assertHasNoErrors();

    $prescription = Prescription::query()->sole();
    expect($prescription->isVerified())->toBeTrue()
        ->and((float) $prescription->items()->sole()->quantity_prescribed)->toBe(12.0);

    Livewire::test(Prescriptions::class)
        ->call('confirmDelete', $prescription->id)
        ->call('delete');

    expect($prescription->fresh()->status->value)->toBe('cancelled');
});
