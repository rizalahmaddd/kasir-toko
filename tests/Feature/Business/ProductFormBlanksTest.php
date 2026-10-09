<?php

use App\Enums\StoreType;
use App\Livewire\MasterData\Products;
use App\Models\Product;
use App\Support\CurrentTenant;
use App\Support\Features;
use Livewire\Livewire;

test('blank optional business fields in the product form are saved as empty, not rejected', function () {
    $user = actingAsSuperAdmin();
    $user->tenant->forceFill(['store_type' => StoreType::Pharmacy])->save();
    app(CurrentTenant::class)->set($user->tenant->fresh());
    Features::setEnabled(Features::optInFeatures());

    Livewire::test(Products::class)
        ->call('openCreateModal')
        ->set('name', 'Kapas')
        ->set('price', '5000')
        ->set('custom_attributes.strength', '')
        ->set('custom_attributes.dosage_form', '')
        ->call('addUnit')
        ->set('units.0.name', 'pak')
        ->set('units.0.factor', '10')
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::query()->where('name', 'Kapas')->sole();
    expect($product->drug_class)->toBeNull()
        ->and($product->custom_attributes)->toBeNull()
        ->and($product->units()->sole()->price)->toBeNull()
        ->and($product->units()->sole()->barcode)->toBeNull();
});
