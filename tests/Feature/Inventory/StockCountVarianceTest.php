<?php

use App\Enums\StockCountReason;
use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Setting;
use App\Models\StockCount;
use App\Models\StockCountCorrection;
use App\Models\StockCountEntry;
use App\Models\StockMovement;
use App\Services\Pos\SaleService;
use App\Services\Pos\ShiftService;
use App\Services\Pos\StockCountService;
use App\Services\Pos\StockCountVariance;
use App\Support\CurrentOutlet;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->admin = actingAsAdmin();
    $this->main = primaryOutlet();
    $this->counts = app(StockCountService::class);
    $this->product = Product::factory()->create(['cost_price' => 4000, 'price' => 6000]);
    setOutletStock($this->product, $this->main->id, 10);
});

function varianceEntry(StockCount $count, Product $product, float $quantity): StockCountEntry
{
    return app(StockCountService::class)->recordEntry($count, test()->admin, ['client_uuid' => (string) Str::uuid(), 'product_id' => $product->id, 'quantity' => $quantity]);
}

function sellOne(Product $product, float $quantity = 1): void
{
    app(SaleService::class)->checkout(test()->admin, [
        'client_uuid' => (string) Str::uuid(),
        'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'price' => $product->price]],
        'payments' => [['method' => 'cash', 'amount' => 1000000]],
    ]);
}

function postCount(StockCount $count, string $policy = StockCount::UNCOUNTED_KEEP): StockCount
{
    $service = app(StockCountService::class);
    $service->submit($count, test()->admin);

    return $service->post($count->fresh(), test()->admin, $policy);
}

test('a sale after the product was counted does not change its variance', function () {
    app(ShiftService::class)->open($this->admin, 0);
    $this->travelTo(now()->setTime(10, 0));
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->product->id]);
    varianceEntry($count, $this->product, 8);

    $this->travelTo(now()->setTime(11, 0));
    sellOne($this->product);

    $this->travelTo(now()->setTime(12, 0));
    $posted = postCount($count);
    $item = $posted->items()->first();
    $movement = StockMovement::query()->where('type', StockMovementType::Opname)->sole();

    expect(outletStockQty($this->product, $this->main->id))->toBe(7.0)
        ->and((float) $item->variance_qty)->toBe(-2.0)
        ->and((float) $item->reference_system_qty)->toBe(10.0)
        ->and($item->stock_movement_id)->toBe($movement->id)
        ->and((float) $movement->quantity)->toBe(-2.0)
        ->and($movement->unit_cost)->toBe(4000)
        ->and($movement->reference->is($posted))->toBeTrue()
        ->and($movement->note)->toBe($posted->number)
        ->and($posted->status)->toBe(StockCountStatus::Posted)
        ->and($posted->summary)->toMatchArray(['changed' => 1, 'shortage_qty' => 2, 'shortage_value' => 8000, 'counted' => 1]);
});

test('counts from several places add up and decimals are kept', function () {
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->product->id]);
    varianceEntry($count, $this->product, 5);
    varianceEntry($count, $this->product, 6.255);

    postCount($count);

    expect(outletStockQty($this->product, $this->main->id))->toBe(11.255)
        ->and((float) $count->items()->value('counted_qty'))->toBe(11.255);
});

test('uncounted products keep their stock or are counted as empty when asked', function () {
    $other = Product::factory()->create(['cost_price' => 1000]);
    setOutletStock($other, $this->main->id, 6);

    $keep = $this->counts->start($this->admin, $this->main->id, StockCountScope::All);
    varianceEntry($keep, $this->product, 10);
    $preview = $this->counts->preview($keep);
    postCount($keep);

    expect($preview)->toMatchArray(['uncounted' => 1, 'uncounted_qty' => 6.0, 'uncounted_value' => 6000])
        ->and(outletStockQty($other, $this->main->id))->toBe(6.0)
        ->and(StockMovement::query()->where('type', StockMovementType::Opname)->exists())->toBeFalse();

    $zero = $this->counts->start($this->admin, $this->main->id, StockCountScope::All);
    varianceEntry($zero, $this->product, 10);
    $posted = postCount($zero, StockCount::UNCOUNTED_ZERO);
    $item = $posted->items()->where('product_id', $other->id)->first();

    expect(outletStockQty($other, $this->main->id))->toBe(0.0)
        ->and((float) $item->variance_qty)->toBe(-6.0)
        ->and($item->entries()->sole()->source)->toBe(StockCountEntry::SOURCE_POLICY)
        ->and($posted->uncounted_policy)->toBe(StockCount::UNCOUNTED_ZERO);
});

test('posting keeps the shop total equal to the sum of outlet stocks', function () {
    $branch = makeOutlet(['code' => 'CB1']);
    setOutletStock($this->product, $branch->id, 4);

    $count = $this->counts->start($this->admin, $branch->id, StockCountScope::All);
    varianceEntry($count, $this->product, 1);
    postCount($count);

    $product = $this->product->fresh();

    expect(outletStockQty($product, $branch->id))->toBe(1.0)
        ->and(outletStockQty($product, $this->main->id))->toBe(10.0)
        ->and((float) $product->stock)->toBe((float) ProductStock::query()->where('product_id', $product->id)->sum('stock'))
        ->and($product->cost_price)->toBe(4000);
});

test('a big variance is suggested for recount and needs a reason above the threshold', function () {
    Setting::put(StockCountService::REASON_REQUIRED_ABOVE_KEY, '10000');
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->product->id]);
    varianceEntry($count, $this->product, 4);
    $this->counts->submit($count, $this->admin);

    $this->counts->preview($count);
    $item = $count->items()->first();

    expect($item->hasFlag(StockCountVariance::FLAG_RECOUNT_SUGGESTED))->toBeTrue()
        ->and(posRejection(fn () => $this->counts->post($count->fresh(), $this->admin))->reason)->toBe('stock_count_reason_required');

    $this->counts->setReason($item, $this->admin, StockCountReason::Damaged);
    $this->counts->post($count->fresh(), $this->admin);

    expect(outletStockQty($this->product, $this->main->id))->toBe(4.0)
        ->and(StockMovement::query()->where('type', StockMovementType::Opname)->value('note'))->toBe("{$count->number} · Rusak");
});

test('posting twice does not move stock twice', function () {
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->product->id]);
    varianceEntry($count, $this->product, 7);
    postCount($count);

    expect(posRejection(fn () => $this->counts->post($count->fresh(), $this->admin))->reason)->toBe('stock_count_closed')
        ->and(outletStockQty($this->product, $this->main->id))->toBe(7.0)
        ->and(StockMovement::query()->where('type', StockMovementType::Opname)->count())->toBe(1);
});

test('a count must be reviewed before it can be posted and a locked outlet cannot post', function () {
    $branch = makeOutlet(['code' => 'CB1']);
    $count = $this->counts->start($this->admin, $branch->id, StockCountScope::All);

    expect(posRejection(fn () => $this->counts->post($count, $this->admin))->getMessage())->toContain('Periksa');

    $this->counts->submit($count, $this->admin);
    $this->tenant->update(['plan' => 'free']);
    app(CurrentOutlet::class)->flush();

    expect(posRejection(fn () => $this->counts->post($count->fresh(), $this->admin))->reason)->toBe('outlet_locked');
});

function sellOffline(Product $product, string $occurredAt, ?string $sentAt = null, float $quantity = 1)
{
    return app(SaleService::class)->checkout(test()->admin, [
        'client_uuid' => (string) Str::uuid(),
        'offline' => true,
        'occurred_at' => $occurredAt,
        'device_sent_at' => $sentAt ?? now()->toIso8601String(),
        'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'price' => $product->price]],
        'payments' => [['method' => 'cash', 'amount' => 1000000]],
    ]);
}

test('an offline sale made before counting but synced before posting is not counted as missing', function () {
    $this->travelTo(now()->setTime(8, 0));
    app(ShiftService::class)->open($this->admin, 0);
    $this->travelTo(now()->setTime(9, 0));
    $soldAt = now()->toIso8601String();

    $this->travelTo(now()->setTime(10, 0));
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->product->id]);
    varianceEntry($count, $this->product, 9);

    $this->travelTo(now()->setTime(11, 0));
    sellOffline($this->product, $soldAt);

    $posted = postCount($count);

    expect((float) $posted->items()->value('variance_qty'))->toBe(0.0)
        ->and(outletStockQty($this->product, $this->main->id))->toBe(9.0)
        ->and(StockMovement::query()->where('type', StockMovementType::Opname)->exists())->toBeFalse();
});

test('an offline sale that arrives after posting is corrected once', function () {
    $this->travelTo(now()->setTime(8, 0));
    app(ShiftService::class)->open($this->admin, 0);
    $this->travelTo(now()->setTime(9, 0));
    $soldAt = now()->toIso8601String();

    $this->travelTo(now()->setTime(10, 0));
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->product->id]);
    varianceEntry($count, $this->product, 9);
    $posted = postCount($count);

    expect(outletStockQty($this->product, $this->main->id))->toBe(9.0);

    $this->travelTo(now()->setTime(12, 0));
    $sale = sellOffline($this->product, $soldAt);
    $item = $posted->items()->sole();
    $correction = StockCountCorrection::query()->sole();

    expect(outletStockQty($this->product, $this->main->id))->toBe(9.0)
        ->and((float) $item->variance_qty)->toBe(0.0)
        ->and($correction->sale_id)->toBe($sale->id)
        ->and((float) $correction->quantity)->toBe(1.0)
        ->and($correction->stockMovement->note)->toContain('Koreksi susulan')
        ->and((float) $this->product->fresh()->stock)->toBe(9.0);

    sellOffline($this->product, now()->toIso8601String());

    expect(StockCountCorrection::query()->count())->toBe(1)
        ->and(outletStockQty($this->product, $this->main->id))->toBe(8.0);
});

test('a wrong phone clock is corrected and never goes before the shift opened', function () {
    $this->travelTo(now()->setTime(8, 0));
    app(ShiftService::class)->open($this->admin, 0);
    $this->travelTo(now()->setTime(12, 0));

    sellOffline($this->product, now()->subHours(3)->toIso8601String(), now()->subHours(2)->toIso8601String());
    sellOffline($this->product, now()->subDays(2)->toIso8601String(), now()->toIso8601String());

    $times = StockMovement::query()->where('type', StockMovementType::Sale)->orderBy('id')->pluck('occurred_at');

    expect($times[0]->format('H:i'))->toBe('11:00')
        ->and($times[1]->format('H:i'))->toBe('08:00');

    sellOne($this->product);

    expect(StockMovement::query()->where('type', StockMovementType::Sale)->latest('id')->value('occurred_at'))->toBeNull();
});

test('a count queued offline uses the stock from when it was counted', function () {
    $this->travelTo(now()->setTime(8, 0));
    app(ShiftService::class)->open($this->admin, 0);
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->product->id]);
    $this->travelTo(now()->setTime(10, 0));
    $countedAt = now()->toIso8601String();

    $this->travelTo(now()->setTime(11, 0));
    sellOne($this->product, 2);

    $this->travelTo(now()->setTime(12, 0));
    $entry = $this->counts->recordEntry($count, $this->admin, ['client_uuid' => (string) Str::uuid(), 'product_id' => $this->product->id, 'quantity' => 10, 'counted_at' => $countedAt]);

    expect((float) $entry->system_qty_at_count)->toBe(10.0)
        ->and((float) $count->items()->value('variance_qty'))->toBe(0.0);
});
