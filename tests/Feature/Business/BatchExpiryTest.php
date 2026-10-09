<?php

use App\Enums\BatchSource;
use App\Enums\StockMovementType;
use App\Livewire\Inventory\StockIndex;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductStock;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ProductsExpiringNotification;
use App\Services\BusinessCapabilities;
use App\Services\Pos\PosException;
use App\Services\Pos\SaleService;
use App\Services\Pos\ShiftService;
use App\Services\Pos\StockService;
use App\Services\Pos\StockTransferService;
use App\Support\Features;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

function batchSale(User $cashier, Product $product, float $quantity, array $extra = [])
{
    return app(SaleService::class)->checkout($cashier, [
        'client_uuid' => (string) Str::uuid(),
        'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'price' => $product->price]],
        'payments' => [['method' => 'cash', 'amount' => 10_000_000]],
        ...$extra,
    ]);
}

function receiveBatch(Product $product, User $user, float $quantity, string $number, ?string $expires, ?int $outletId = null): void
{
    app(StockService::class)->adjust($product, StockMovementType::StockIn, $quantity, $user, null, null, $outletId, null, ['number' => $number, 'expires_at' => $expires]);
}

/**
 * @return array<string, float>
 */
function batchBalances(Product $product, ?int $outletId = null): array
{
    return ProductBatch::query()->where('product_id', $product->id)->where('outlet_id', $outletId ?? primaryOutlet()->id)
        ->get()->mapWithKeys(fn (ProductBatch $batch) => [($batch->batch_number ?? '-') => (float) $batch->quantity])->all();
}

beforeEach(function () {
    Features::setEnabled(['business.batch-expiry']);
    $this->user = actingAsRole('admin');
    $this->product = Product::factory()->create(['price' => 1000, 'cost_price' => 600, 'stock' => 0, 'track_batch' => true]);
});

test('stock in for a batch-tracked product needs a batch number', function () {
    expect(fn () => app(StockService::class)->adjust($this->product, StockMovementType::StockIn, 5, $this->user))
        ->toThrow(PosException::class, 'nomor batch');
});

test('sales take the batch that expires first and voids put it back', function () {
    receiveBatch($this->product, $this->user, 10, 'LATE', today()->addMonths(6)->toDateString());
    receiveBatch($this->product, $this->user, 5, 'SOON', today()->addMonth()->toDateString());
    app(ShiftService::class)->open($this->user, 0);

    $sale = batchSale($this->user, $this->product, 7);

    expect(batchBalances($this->product))->toEqual(['LATE' => 8.0, 'SOON' => 0.0])
        ->and($sale->items->sole()->batches()->with('batch')->get()->mapWithKeys(fn ($line) => [$line->batch->batch_number => (float) $line->quantity])->all())->toEqual(['SOON' => 5.0, 'LATE' => 2.0]);

    app(SaleService::class)->void($sale, $this->user, 'Batal');

    expect(batchBalances($this->product))->toEqual(['LATE' => 10.0, 'SOON' => 5.0]);
});

test('expired batches are refused online but an offline sale is accepted and flagged', function () {
    receiveBatch($this->product, $this->user, 3, 'OLD', today()->subDay()->toDateString());
    receiveBatch($this->product, $this->user, 2, 'NEW', today()->addYear()->toDateString());
    app(ShiftService::class)->open($this->user, 0);

    try {
        batchSale($this->user, $this->product, 4);
        $this->fail('Online sale of expired stock should be refused.');
    } catch (PosException $exception) {
        expect($exception->reason)->toBe('expired_batch');
    }

    expect(batchBalances($this->product))->toEqual(['OLD' => 3.0, 'NEW' => 2.0]);

    $sale = batchSale($this->user, $this->product, 4, ['offline' => true]);

    expect($sale->fresh()->flags)->toBe(['expired_batch_sold'])
        ->and(batchBalances($this->product))->toEqual(['OLD' => 0.0, 'NEW' => 1.0]);
});

test('the expired-sale block can be switched off', function () {
    Setting::put('pos.block_expired_sale', '0');
    receiveBatch($this->product, $this->user, 3, 'OLD', today()->subDay()->toDateString());
    app(ShiftService::class)->open($this->user, 0);

    batchSale($this->user, $this->product, 2);

    expect(batchBalances($this->product))->toBe(['OLD' => 1.0]);
});

test('transfers recreate the same batches at the destination outlet', function () {
    $branch = makeOutlet(['code' => 'CB2']);
    $expires = today()->addMonths(3)->toDateString();
    receiveBatch($this->product, $this->user, 6, 'B-1', $expires);

    $transfer = app(StockTransferService::class)->create($this->user, primaryOutlet()->id, $branch->id, [['product_id' => $this->product->id, 'quantity' => 4]]);

    $moved = ProductBatch::query()->where('outlet_id', $branch->id)->sole();
    expect($moved->batch_number)->toBe('B-1')
        ->and($moved->expires_at->toDateString())->toBe($expires)
        ->and((float) $moved->quantity)->toBe(4.0)
        ->and(batchBalances($this->product))->toBe(['B-1' => 2.0]);

    app(StockTransferService::class)->cancel($transfer, $this->user);

    expect(batchBalances($this->product))->toBe(['B-1' => 6.0])
        ->and(batchBalances($this->product, $branch->id))->toBe(['B-1' => 0.0]);
});

test('switching the capability on opens a balance batch for stock that already exists', function () {
    Features::setEnabled([]);
    $existing = Product::factory()->create(['stock' => 0, 'track_batch' => true]);
    app(StockService::class)->adjust($existing, StockMovementType::StockIn, 12, $this->user);
    expect(ProductBatch::query()->count())->toBe(0);

    app(BusinessCapabilities::class)->sync(['business.batch-expiry']);

    $opening = ProductBatch::query()->where('product_id', $existing->id)->sole();
    expect($opening->source)->toBe(BatchSource::Opening)
        ->and((float) $opening->quantity)->toBe(12.0)
        ->and($opening->batch_number)->toBeNull();
});

test('batch totals always match outlet stock through random stock operations', function () {
    Setting::put('pos.allow_negative_stock', '1');
    $branch = makeOutlet(['code' => 'CB3']);
    app(ShiftService::class)->open($this->user, 0);
    mt_srand(20261005);
    $sales = [];

    for ($step = 0; $step < 60; $step++) {
        $roll = mt_rand(1, 6);
        $quantity = mt_rand(1, 9);

        match ($roll) {
            1, 2 => receiveBatch($this->product, $this->user, $quantity, 'B'.mt_rand(1, 4), today()->addDays(mt_rand(-5, 90))->toDateString()),
            3 => $sales[] = batchSale($this->user, $this->product, $quantity, ['offline' => true]),
            4 => ($sale = array_pop($sales)) ? app(SaleService::class)->void($sale, $this->user, 'acak') : null,
            5 => rescue(fn () => app(StockService::class)->adjust($this->product, StockMovementType::Opname, mt_rand(0, 30), $this->user), report: false),
            6 => app(StockTransferService::class)->create($this->user, primaryOutlet()->id, $branch->id, [['product_id' => $this->product->id, 'quantity' => $quantity]]),
        };
    }

    foreach (ProductStock::query()->where('product_id', $this->product->id)->get() as $stock) {
        $batches = (float) ProductBatch::query()->where('product_id', $this->product->id)->where('outlet_id', $stock->outlet_id)->sum('quantity');
        expect(round($batches, 3))->toBe(round((float) $stock->stock, 3), "outlet {$stock->outlet_id}");
    }

    $this->artisan('stock:verify-batches')->assertSuccessful();
});

test('pro shops get a daily summary of expiring stock', function () {
    Notification::fake();
    receiveBatch($this->product, $this->user, 3, 'OLD', today()->subDay()->toDateString());
    receiveBatch($this->product, $this->user, 3, 'SOON', today()->addDays(10)->toDateString());

    $this->artisan('stock:notify-expiring')->assertSuccessful();

    Notification::assertSentTo($this->user, ProductsExpiringNotification::class, fn ($notification) => $notification->expiredCount === 1 && $notification->soonCount === 1);
});

test('the expiring stock API lists batches about to expire', function () {
    receiveBatch($this->product, $this->user, 3, 'SOON', today()->addDays(5)->toDateString());
    receiveBatch($this->product, $this->user, 3, 'LATER', today()->addYear()->toDateString());
    apiActingAs('admin');

    $this->getJson('/api/v1/inventory/expiring?days=30')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.batch_number', 'SOON')
        ->assertJsonPath('data.0.days_left', 5);

    $this->getJson("/api/v1/inventory/products/{$this->product->id}/batches")
        ->assertOk()
        ->assertJsonPath('data.0.batch_number', 'SOON')
        ->assertJsonPath('data.1.batch_number', 'LATER');
});

test('a batch opname sets each counted batch and records one movement with per-batch differences', function () {
    $product = Product::factory()->create(['name' => 'Amoxicillin', 'unit' => 'tablet', 'stock' => 0, 'track_batch' => true]);
    $stock = app(StockService::class);
    $stock->adjust($product, StockMovementType::StockIn, 30, $this->user, batch: ['number' => 'A1', 'expires_at' => now()->addYear()->toDateString()]);
    $stock->adjust($product, StockMovementType::StockIn, 20, $this->user, batch: ['number' => 'B2', 'expires_at' => now()->addYears(2)->toDateString()]);
    $batches = $product->batches()->get()->keyBy('batch_number');

    $movement = $stock->opnameBatches($product, [$batches['A1']->id => 28, $batches['B2']->id => 25], auth()->user(), extra: ['number' => 'C3', 'expires_at' => null, 'quantity' => 5]);

    expect((float) $movement->quantity)->toBe(8.0)
        ->and((float) $product->fresh()->stock)->toBe(58.0)
        ->and((float) $batches['A1']->fresh()->quantity)->toBe(28.0)
        ->and((float) $batches['B2']->fresh()->quantity)->toBe(25.0)
        ->and($movement->batchLines->pluck('quantity')->map(fn ($q) => (float) $q)->sort()->values()->all())->toBe([-2.0, 5.0, 5.0])
        ->and((float) $product->batches()->sum('quantity'))->toBe(58.0);

    expect(fn () => $stock->opnameBatches($product, [$batches['A1']->id => 28], auth()->user()))->toThrow(PosException::class, 'tidak ada yang perlu');

    apiActingAs('admin');
    $this->postJson('/api/v1/inventory/batch-opname', ['product_id' => $product->id, 'counts' => [$batches['A1']->id => 27]])->assertCreated()->assertJsonPath('data.quantity', '-1.000');
});

test('the stock page counts each batch during opname', function () {
    $product = Product::factory()->create(['name' => 'Cefadroxil', 'unit' => 'kapsul', 'stock' => 0, 'track_batch' => true]);
    receiveBatch($product, $this->user, 12, 'X1', today()->addYear()->toDateString());
    $batch = $product->batches()->sole();

    Livewire::test(StockIndex::class)
        ->call('openAdjust', $product->id, 'opname')
        ->assertSet("opnameCounts.{$batch->id}", '12')
        ->set("opnameCounts.{$batch->id}", '10')
        ->call('saveBatchOpname')
        ->assertHasNoErrors();

    expect((float) $batch->fresh()->quantity)->toBe(10.0)
        ->and((float) $product->fresh()->stock)->toBe(10.0);
});

test('units from a near-expiry batch get the automatic discount and stale screens are refused', function () {
    Setting::putMany(['pos.near_expiry_discount_percent' => '30', 'pos.near_expiry_discount_days' => '2']);
    $bread = Product::factory()->create(['name' => 'Roti Sobek', 'unit' => 'pcs', 'price' => 12000, 'stock' => 0, 'track_batch' => true]);
    receiveBatch($bread, $this->user, 2, 'H1', today()->addDay()->toDateString());
    receiveBatch($bread, $this->user, 10, 'H3', today()->addDays(5)->toDateString());
    app(ShiftService::class)->open($this->user, 0);
    $line = ['product_id' => $bread->id, 'quantity' => 3, 'price' => 12000];

    $error = null;
    try {
        batchSale($this->user, $bread, 3, ['items' => [$line]]);
    } catch (PosException $exception) {
        $error = $exception;
    }
    expect($error?->reason)->toBe('near_expiry_changed')
        ->and($error->context['near_expiry'][$bread->id])->toBe(['quantity' => 2.0, 'percent' => 30.0]);

    $sale = batchSale($this->user, $bread, 3, ['items' => [[...$line, 'auto_discount' => 7200]]]);

    expect($sale->total)->toBe(36000 - 7200)
        ->and($sale->items->sole()->auto_discount)->toBe(7200);

    $this->get(route('pos.receipt', $sale))->assertOk()->assertSee('Diskon ED');
});
