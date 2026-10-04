<?php

use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Product;

it('creates a product with its opening stock on the stock card', function () {
    apiActingAs('admin');

    $id = $this->postJson('/api/v1/master-data/products', [
        'name' => 'Kopi Susu', 'unit' => 'gelas', 'price' => 18000, 'cost_price' => 9000, 'stock' => 12,
    ])->assertCreated()->assertJsonPath('data.stock', '12.000')->json('data.id');

    $product = Product::findOrFail($id);
    expect($product->sku)->toStartWith('PRD')
        ->and($product->stockMovements()->sole()->type)->toBe(StockMovementType::Initial);
});

it('keeps flags the client left out when updating a product', function () {
    apiActingAs('admin');
    $product = Product::factory()->inactive()->create(['stock' => 7]);

    $this->putJson("/api/v1/master-data/products/{$product->id}", [
        'sku' => $product->sku, 'name' => 'Nama Baru', 'unit' => 'pcs', 'price' => 5000, 'stock' => 999,
    ])->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.stock', '7.000');
});

it('keeps roles without the manage permission from changing products', function () {
    apiActingAs('kasir');

    $this->postJson('/api/v1/master-data/products', ['name' => 'X', 'unit' => 'pcs', 'price' => 1000])->assertForbidden();
    $this->getJson('/api/v1/master-data/products')->assertOk();
});

it('rejects a barcode already used by another product', function () {
    apiActingAs('admin');
    Product::factory()->create(['barcode' => '8991234567890']);

    $this->postJson('/api/v1/master-data/products', ['name' => 'X', 'unit' => 'pcs', 'price' => 1000, 'barcode' => '8991234567890'])
        ->assertJsonValidationErrors(['barcode' => 'Barcode ini sudah dipakai produk lain.']);
});

it('keeps products when their category is deleted', function () {
    apiActingAs('admin');
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);

    $this->deleteJson("/api/v1/master-data/categories/{$category->id}")->assertNoContent();

    expect($product->fresh()->category_id)->toBeNull();
});

it('records stock adjustments for roles that manage inventory', function () {
    apiActingAs('staff');
    $product = Product::factory()->create(['stock' => 10]);

    $this->postJson('/api/v1/inventory/adjustments', ['product_id' => $product->id, 'type' => 'opname', 'quantity' => 8])
        ->assertCreated()
        ->assertJsonPath('data.quantity', '-2.000')
        ->assertJsonPath('data.stock_after', '8.000');

    $this->postJson('/api/v1/inventory/adjustments', ['product_id' => $product->id, 'type' => 'stock_out', 'quantity' => 1])
        ->assertJsonValidationErrors(['note' => 'Tulis alasan stok keluar, mis. rusak, kedaluwarsa, dipakai sendiri.']);
});

it('keeps cashiers from adjusting stock', function () {
    apiActingAs('kasir');
    $product = Product::factory()->create();

    $this->postJson('/api/v1/inventory/adjustments', ['product_id' => $product->id, 'type' => 'stock_in', 'quantity' => 5])->assertForbidden();
});

it('returns business rule rejections from the stock service as 422', function () {
    apiActingAs('staff');
    $product = Product::factory()->untracked()->create();

    $this->postJson('/api/v1/inventory/adjustments', ['product_id' => $product->id, 'type' => 'stock_in', 'quantity' => 5])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'invalid');
});

it('frees the barcode of a deleted product for a new one', function () {
    apiActingAs('admin');
    $old = Product::factory()->create(['barcode' => '8997777777777']);

    $this->deleteJson("/api/v1/master-data/products/{$old->id}")->assertNoContent();

    $this->postJson('/api/v1/master-data/products', ['name' => 'Pengganti', 'unit' => 'pcs', 'price' => 1000, 'barcode' => '8997777777777'])
        ->assertCreated();
});
