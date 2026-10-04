<?php

use App\Models\Product;
use App\Services\Pos\SaleService;
use App\Services\Pos\ShiftService;
use Illuminate\Support\Str;

it('summarises sales the same way as the web report', function () {
    $admin = apiActingAs('admin');
    $product = Product::factory()->create(['price' => 20000, 'cost_price' => 12000]);
    app(ShiftService::class)->open($admin, 0);
    app(SaleService::class)->checkout($admin, [
        'client_uuid' => (string) Str::uuid(),
        'items' => [['product_id' => $product->id, 'quantity' => 2, 'price' => 20000]],
        'payments' => [['method' => 'qris', 'amount' => 40000]],
    ]);

    $this->getJson('/api/v1/reports/sales/summary')
        ->assertOk()
        ->assertJsonPath('data.totals.revenue', 40000)
        ->assertJsonPath('data.totals.profit', 16000)
        ->assertJsonPath('data.payments.0.method', 'qris')
        ->assertJsonPath('data.top_products.0.product_name', $product->name);

    $this->getJson('/api/v1/reports/sales/daily')->assertJsonPath('data.summary.total', 40000);
    $this->getJson('/api/v1/reports/sales/products')->assertJsonPath('data.products.0.qty', 2);
});

it('keeps cashiers out of the reports', function () {
    apiActingAs('kasir');

    $this->getJson('/api/v1/reports/sales/summary')->assertForbidden();
    $this->getJson('/api/v1/reports/activity-log')->assertForbidden();
});

it('lists audit trail entries for roles that may read them', function () {
    apiActingAs('admin');
    Product::factory()->create(['name' => 'Produk Teraudit']);

    $this->getJson('/api/v1/reports/activity-log?search=Teraudit')
        ->assertOk()
        ->assertJsonPath('data.0.event', 'created');
});
