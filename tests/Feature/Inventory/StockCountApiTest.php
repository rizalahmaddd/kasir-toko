<?php

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use App\Services\Pos\StockService;
use App\Support\Features;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->admin = apiActingAs('admin');
    $this->main = primaryOutlet();
    $this->product = Product::factory()->create(['name' => 'Kopi Sachet', 'sku' => 'KOPI-1', 'barcode' => '8991111', 'cost_price' => 1500]);
    $this->box = $this->product->units()->create(['name' => 'renceng', 'factor' => 10, 'barcode' => 'RENCENG-1', 'sort_order' => 1]);
    setOutletStock($this->product, $this->main->id, 25);
});

test('a manager starts, counts, reviews, and posts a count through the api', function () {
    $id = $this->postJson('/api/v1/inventory/stock-counts', ['scope' => 'all', 'note' => 'Akhir bulan'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'counting')
        ->assertJsonPath('data.can_see_system', true)
        ->json('data.id');

    $this->getJson("/api/v1/inventory/stock-counts/{$id}/lookup?code=RENCENG-1")
        ->assertOk()->assertJsonPath('kind', 'product')->assertJsonPath('unit_id', $this->box->id)->assertJsonPath('item.product.id', $this->product->id);

    $this->getJson("/api/v1/inventory/stock-counts/{$id}/catalog")->assertOk()->assertJsonPath('data.0.units.0.barcode', 'RENCENG-1');

    $uuid = (string) Str::uuid();
    $payload = ['device_sent_at' => now()->toIso8601String(), 'entries' => [
        ['client_uuid' => $uuid, 'product_id' => $this->product->id, 'quantity' => 2, 'unit_id' => $this->box->id, 'counted_at' => now()->toIso8601String()],
        ['client_uuid' => (string) Str::uuid(), 'product_id' => 999999, 'quantity' => 1],
    ]];

    $this->postJson("/api/v1/inventory/stock-counts/{$id}/entries", $payload)
        ->assertOk()
        ->assertJsonPath('data.0.status', 'saved')
        ->assertJsonPath('data.1.status', 'rejected');

    $this->postJson("/api/v1/inventory/stock-counts/{$id}/entries", ['entries' => [$payload['entries'][0]]])
        ->assertOk()->assertJsonPath('data.0.status', 'duplicate');

    expect(StockCountEntry::query()->count())->toBe(1);

    $this->getJson("/api/v1/inventory/stock-counts/{$id}/items?filter=counted")
        ->assertOk()->assertJsonPath('data.0.counted_qty', 20)->assertJsonPath('data.0.variance_qty', -5);

    $this->postJson("/api/v1/inventory/stock-counts/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'review');
    $this->getJson("/api/v1/inventory/stock-counts/{$id}/preview")->assertOk()->assertJsonPath('data.shortage_value', 7500);

    $itemId = StockCount::query()->find($id)->items()->value('id');
    $this->patchJson("/api/v1/inventory/stock-counts/{$id}/items/{$itemId}", ['reason' => 'damaged'])->assertOk()->assertJsonPath('data.reason', 'damaged');

    $this->postJson("/api/v1/inventory/stock-counts/{$id}/post", ['uncounted_policy' => 'keep'])->assertOk()->assertJsonPath('data.status', 'posted');

    expect(outletStockQty($this->product, $this->main->id))->toBe(20.0);

    $this->postJson("/api/v1/inventory/stock-counts/{$id}/entries", ['entries' => [['client_uuid' => (string) Str::uuid(), 'product_id' => $this->product->id, 'quantity' => 1]]])
        ->assertOk()->assertJsonPath('data.0.reason', 'stock_count_closed');

    $this->getJson("/api/v1/inventory/movements?product_id={$this->product->id}")
        ->assertOk()->assertJsonPath('data.0.stock_count_id', $id);
});

test('a counter cannot start counts and does not get system numbers on a blind count', function () {
    $id = $this->postJson('/api/v1/inventory/stock-counts', ['scope' => 'all'])->json('data.id');
    apiActingAs('staff');

    $this->postJson('/api/v1/inventory/stock-counts', ['scope' => 'all'])->assertForbidden();
    $this->getJson("/api/v1/inventory/stock-counts/{$id}")->assertOk()->assertJsonPath('data.can_see_system', false)->assertJsonPath('data.can_manage', false);
    $this->getJson("/api/v1/inventory/stock-counts/{$id}/items")->assertOk()->assertJsonPath('data.0.expected_qty', null)->assertJsonPath('data.0.variance_qty', null);
    $this->postJson("/api/v1/inventory/stock-counts/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'counting');
    $this->postJson("/api/v1/inventory/stock-counts/{$id}/post")->assertForbidden();
});

test('a cashier without the count permission is refused and a disabled feature hides the endpoints', function () {
    $id = $this->postJson('/api/v1/inventory/stock-counts', ['scope' => 'all'])->json('data.id');
    apiActingAs('kasir');

    $this->getJson("/api/v1/inventory/stock-counts/{$id}")->assertForbidden();

    apiActingAs('admin');
    Features::setDisabled(['inventory.opname']);

    $this->getJson('/api/v1/inventory/stock-counts')->assertStatus(403);
});

test('serial scans report their result per unit', function () {
    Features::setEnabled(['business.serial-number']);
    $phone = Product::factory()->create(['stock' => 0, 'track_serial' => true]);
    app(StockService::class)->adjust($phone, StockMovementType::StockIn, 1, $this->admin, batch: ['serials' => ['IMEI-A']]);
    $id = $this->postJson('/api/v1/inventory/stock-counts', ['scope' => 'products', 'product_ids' => [$phone->id]])->json('data.id');

    $this->postJson("/api/v1/inventory/stock-counts/{$id}/serials", ['serials' => [['serial' => 'imei-a'], ['serial' => 'NEW-B', 'product_id' => $phone->id], ['serial' => 'GHOST']]])
        ->assertOk()
        ->assertJsonPath('data.0.result', 'matched')
        ->assertJsonPath('data.1.result', 'unknown')
        ->assertJsonPath('data.2.reason', 'serial_unknown_product');

    $this->postJson("/api/v1/inventory/stock-counts/{$id}/unknown", ['barcode' => '123', 'quantity' => 2])->assertCreated();
});

test('a count can only be changed from its own outlet', function () {
    $branch = makeOutlet(['code' => 'CB1']);
    $id = $this->postJson('/api/v1/inventory/stock-counts', ['scope' => 'all'])->json('data.id');
    $other = ['X-Outlet-Id' => (string) $branch->id];

    $this->getJson('/api/v1/inventory/stock-counts?status=open', $other)->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/inventory/stock-counts/{$id}/submit", [], $other)->assertStatus(422)->assertJsonPath('reason', 'stock_count_other_outlet');
    $this->postJson("/api/v1/inventory/stock-counts/{$id}/entries", ['entries' => [['client_uuid' => (string) Str::uuid(), 'product_id' => $this->product->id, 'quantity' => 1]]], $other)
        ->assertOk()->assertJsonPath('data.0.reason', 'stock_count_other_outlet');

    $this->getJson('/api/v1/inventory/stock-counts?status=open')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/inventory/stock-counts/{$id}/entries", ['entries' => [['client_uuid' => (string) Str::uuid(), 'product_id' => $this->product->id, 'quantity' => 1]]])
        ->assertOk()->assertJsonPath('data.0.status', 'saved');
});
