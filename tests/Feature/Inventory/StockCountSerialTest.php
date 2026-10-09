<?php

use App\Enums\StockCountScope;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\StockCount;
use App\Models\StockCountSerial;
use App\Services\Pos\SaleService;
use App\Services\Pos\ShiftService;
use App\Services\Pos\StockCountService;
use App\Services\Pos\StockService;
use App\Support\Features;
use Illuminate\Support\Str;

beforeEach(function () {
    Features::setEnabled(['business.serial-number']);
    $this->admin = actingAsAdmin();
    $this->main = primaryOutlet();
    $this->counts = app(StockCountService::class);
    $this->phone = Product::factory()->create(['name' => 'HP Opname', 'price' => 1_000_000, 'cost_price' => 800_000, 'stock' => 0, 'track_serial' => true]);
    app(StockService::class)->adjust($this->phone, StockMovementType::StockIn, 3, $this->admin, batch: ['serials' => ['IMEI-1', 'IMEI-2', 'IMEI-3']]);
});

function scanSerial(StockCount $count, string $serial, ?Product $product = null): StockCountSerial
{
    return app(StockCountService::class)->recordSerial($count, test()->admin, ['serial' => $serial, 'product_id' => $product?->id]);
}

function serialStatus(string $serial): string
{
    return ProductSerial::query()->where('serial', $serial)->value('status');
}

function postSerialCount(StockCount $count): StockCount
{
    app(StockCountService::class)->submit($count, test()->admin);

    return app(StockCountService::class)->post($count->fresh(), test()->admin);
}

test('serial products cannot be counted by typing a quantity', function () {
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::All);

    expect(posRejection(fn () => $this->counts->recordEntry($count, $this->admin, ['client_uuid' => (string) Str::uuid(), 'product_id' => $this->phone->id, 'quantity' => 3]))->reason)->toBe('serial_required');
});

test('a unit that was not scanned is removed and an unknown unit can be registered', function () {
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->phone->id]);
    scanSerial($count, 'imei-1');
    scanSerial($count, 'IMEI-1');
    scanSerial($count, 'IMEI-2');
    $unknown = scanSerial($count, 'NEW-9', $this->phone);

    expect($count->serials()->count())->toBe(3)
        ->and($unknown->result)->toBe(StockCountSerial::RESULT_UNKNOWN);

    $this->counts->setSerialAction($unknown, $this->admin, StockCountSerial::ACTION_REGISTER);
    $posted = postSerialCount($count);
    $item = $posted->items()->sole();

    expect(serialStatus('IMEI-3'))->toBe(ProductSerial::REMOVED)
        ->and(serialStatus('NEW-9'))->toBe(ProductSerial::IN_STOCK)
        ->and(outletStockQty($this->phone, $this->main->id))->toBe(3.0)
        ->and((float) $item->counted_qty)->toBe(3.0)
        ->and((float) $item->variance_qty)->toBe(0.0);
});

test('a missing unit lowers the stock and a unit sold after the scan is not counted twice', function () {
    app(ShiftService::class)->open($this->admin, 0);
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->phone->id]);
    scanSerial($count, 'IMEI-1');
    scanSerial($count, 'IMEI-2');

    app(SaleService::class)->checkout($this->admin, [
        'client_uuid' => (string) Str::uuid(),
        'items' => [['product_id' => $this->phone->id, 'quantity' => 1, 'price' => 1_000_000, 'serials' => ['IMEI-1']]],
        'payments' => [['method' => 'cash', 'amount' => 1_000_000]],
    ]);

    $posted = postSerialCount($count);

    expect(serialStatus('IMEI-1'))->toBe(ProductSerial::SOLD)
        ->and(serialStatus('IMEI-2'))->toBe(ProductSerial::IN_STOCK)
        ->and(serialStatus('IMEI-3'))->toBe(ProductSerial::REMOVED)
        ->and(outletStockQty($this->phone, $this->main->id))->toBe(1.0)
        ->and((float) $posted->items()->value('variance_qty'))->toBe(-1.0);
});

test('a unit recorded at another outlet can be moved here with its stock', function () {
    $branch = makeOutlet(['code' => 'CB1']);
    ProductSerial::query()->where('serial', 'IMEI-3')->update(['outlet_id' => $branch->id]);
    setOutletStock($this->phone, $this->main->id, 2);
    setOutletStock($this->phone, $branch->id, 1);

    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->phone->id]);
    scanSerial($count, 'IMEI-1');
    scanSerial($count, 'IMEI-2');
    $moved = scanSerial($count, 'IMEI-3');

    expect($moved->result)->toBe(StockCountSerial::RESULT_OTHER_OUTLET);

    $this->counts->setSerialAction($moved, $this->admin, StockCountSerial::ACTION_RELOCATE);
    postSerialCount($count);

    expect(ProductSerial::query()->where('serial', 'IMEI-3')->value('outlet_id'))->toBe($this->main->id)
        ->and(outletStockQty($this->phone, $this->main->id))->toBe(3.0)
        ->and(outletStockQty($this->phone, $branch->id))->toBe(0.0)
        ->and((float) $this->phone->fresh()->stock)->toBe(3.0);
});

test('a sold unit found on the shelf can be put back into stock', function () {
    ProductSerial::query()->where('serial', 'IMEI-3')->update(['status' => ProductSerial::SOLD]);
    setOutletStock($this->phone, $this->main->id, 2);

    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->phone->id]);
    scanSerial($count, 'IMEI-1');
    scanSerial($count, 'IMEI-2');
    $found = scanSerial($count, 'IMEI-3');

    expect($found->result)->toBe(StockCountSerial::RESULT_SOLD)
        ->and(posRejection(fn () => $this->counts->setSerialAction($found, $this->admin, StockCountSerial::ACTION_RELOCATE))->getMessage())->toContain('tidak bisa');

    $this->counts->setSerialAction($found, $this->admin, StockCountSerial::ACTION_REGISTER);
    postSerialCount($count);

    expect(serialStatus('IMEI-3'))->toBe(ProductSerial::IN_STOCK)
        ->and(outletStockQty($this->phone, $this->main->id))->toBe(3.0);
});

test('a unit sold offline before the count and synced after posting becomes sold without a double cut', function () {
    $this->travelTo(now()->setTime(8, 0));
    app(ShiftService::class)->open($this->admin, 0);
    $this->travelTo(now()->setTime(9, 0));
    $soldAt = now()->toIso8601String();

    $this->travelTo(now()->setTime(10, 0));
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->phone->id]);
    scanSerial($count, 'IMEI-1');
    scanSerial($count, 'IMEI-2');
    postSerialCount($count);

    expect(serialStatus('IMEI-3'))->toBe(ProductSerial::REMOVED)
        ->and(outletStockQty($this->phone, $this->main->id))->toBe(2.0);

    $this->travelTo(now()->setTime(12, 0));
    app(SaleService::class)->checkout($this->admin, [
        'client_uuid' => (string) Str::uuid(),
        'offline' => true,
        'occurred_at' => $soldAt,
        'device_sent_at' => now()->toIso8601String(),
        'items' => [['product_id' => $this->phone->id, 'quantity' => 1, 'price' => 1_000_000, 'serials' => ['IMEI-3']]],
        'payments' => [['method' => 'cash', 'amount' => 1_000_000]],
    ]);

    expect(serialStatus('IMEI-3'))->toBe(ProductSerial::SOLD)
        ->and(outletStockQty($this->phone, $this->main->id))->toBe(2.0);
});
