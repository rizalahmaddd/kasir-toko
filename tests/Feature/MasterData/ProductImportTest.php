<?php

use App\Livewire\MasterData\Products;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductImportService;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    $user = actingAsAdmin();
    $tenant = $user->tenant;
    $tenant->update([
        'plan' => 'pro',
        'trial_ends_at' => now()->addDays(30),
    ]);
});

test('downloading csv template returns valid attachment response', function () {
    $service = app(ProductImportService::class);
    $response = $service->downloadTemplate();

    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('Content-Disposition'))->toContain('attachment; filename="template_import_produk.csv"');
});

test('importing csv creates products with categories, auto sku, and stock movements', function () {
    $csvContent = <<<'CSV'
nama_produk,kategori,sku,barcode,satuan,harga_beli,harga_jual,stok_awal,stok_minimum,lacak_stok
Kopi Espresso,Kopi,SKU-001,899123001,cup,5000,15000,50,5,ya
Croissant Choco,Bakery,,899123002,pcs,8000,20000,20,3,ya
Jasa Titip,,TITIP-01,,kali,0,5000,0,0,tidak
CSV;

    $file = UploadedFile::fake()->createWithContent('products.csv', $csvContent);

    $service = app(ProductImportService::class);
    $result = $service->import($file);

    expect($result['imported'])->toBe(3);
    expect($result['skipped'])->toBe(0);
    expect($result['errors'])->toBeEmpty();

    // Check Espresso
    $espresso = Product::where('name', 'Kopi Espresso')->first();
    expect($espresso)->not->toBeNull();
    expect($espresso->sku)->toBe('SKU-001');
    expect($espresso->category->name)->toBe('Kopi');
    expect((int) $espresso->price)->toBe(15000);
    expect((int) $espresso->cost_price)->toBe(5000);
    expect((float) $espresso->stock)->toBe(50.0);

    // Check Croissant with auto-generated SKU
    $croissant = Product::where('name', 'Croissant Choco')->first();
    expect($croissant)->not->toBeNull();
    expect($croissant->sku)->not->toBeEmpty();
    expect($croissant->category->name)->toBe('Bakery');
    expect((float) $croissant->stock)->toBe(20.0);

    // Check Jasa Titip without category or stock tracking
    $jasa = Product::where('name', 'Jasa Titip')->first();
    expect($jasa)->not->toBeNull();
    expect($jasa->category_id)->toBeNull();
    expect($jasa->track_stock)->toBeFalse();
});

test('import skips rows with duplicate sku or missing product name', function () {
    Product::factory()->create([
        'tenant_id' => auth()->user()->tenant_id,
        'name' => 'Existing Soda',
        'sku' => 'SODA-01',
    ]);

    $csvContent = <<<'CSV'
nama_produk,kategori,sku,barcode,satuan,harga_beli,harga_jual,stok_awal,stok_minimum,lacak_stok
Soda Gembira,Minuman,SODA-01,,can,4000,10000,10,2,ya
,Snack,SNK-01,,pcs,2000,5000,10,2,ya
Es Teh Manis,Minuman,TEH-01,,cup,1000,4000,30,5,ya
CSV;

    $file = UploadedFile::fake()->createWithContent('invalid_rows.csv', $csvContent);

    $service = app(ProductImportService::class);
    $result = $service->import($file);

    expect($result['imported'])->toBe(1); // Only Es Teh Manis
    expect($result['skipped'])->toBe(2);
    expect($result['errors'])->toHaveCount(2);

    expect(Product::where('name', 'Es Teh Manis')->exists())->toBeTrue();
});

test('products livewire component can trigger import', function () {
    $csvContent = <<<'CSV'
nama_produk,kategori,sku,barcode,satuan,harga_beli,harga_jual,stok_awal,stok_minimum,lacak_stok
Ice Cream Vanilla,Dessert,IC-01,,cup,5000,12000,15,2,ya
CSV;

    $file = UploadedFile::fake()->createWithContent('import.csv', $csvContent);

    Livewire::test(Products::class)
        ->set('importFile', $file)
        ->call('processImport')
        ->assertHasNoErrors()
        ->assertDispatched('notify');

    expect(Product::where('name', 'Ice Cream Vanilla')->exists())->toBeTrue();
});
