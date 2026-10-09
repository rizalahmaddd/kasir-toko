<?php

use App\Enums\StockCountScope;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Services\Pos\SaleService;
use App\Services\Pos\ShiftService;
use App\Services\Pos\StockCountPoster;
use App\Services\Pos\StockCountService;
use App\Services\Pos\StockService;
use App\Support\Features;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function () {
    Features::setEnabled(['business.batch-expiry']);
    $this->admin = actingAsAdmin();
    $this->main = primaryOutlet();
    $this->counts = app(StockCountService::class);
    $this->product = Product::factory()->create(['price' => 1000, 'cost_price' => 600, 'stock' => 0, 'track_batch' => true]);

    $stock = app(StockService::class);
    $stock->adjust($this->product, StockMovementType::StockIn, 10, $this->admin, null, null, null, null, ['number' => 'SOON', 'expires_at' => today()->addMonth()->toDateString()]);
    $stock->adjust($this->product, StockMovementType::StockIn, 6, $this->admin, null, null, null, null, ['number' => 'LATE', 'expires_at' => today()->addYear()->toDateString()]);
    $this->soon = ProductBatch::query()->where('batch_number', 'SOON')->sole();
    $this->late = ProductBatch::query()->where('batch_number', 'LATE')->sole();
});

/**
 * @return array<string, float>
 */
function countBatchBalances(Product $product): array
{
    return ProductBatch::query()->where('product_id', $product->id)->where('outlet_id', primaryOutlet()->id)->orderBy('id')
        ->get()->mapWithKeys(fn (ProductBatch $batch) => [($batch->batch_number ?? '-') => (float) $batch->quantity])->all();
}

function batchEntry(StockCount $count, Product $product, float $quantity, array $extra = []): void
{
    app(StockCountService::class)->recordEntry($count, test()->admin, ['client_uuid' => (string) Str::uuid(), 'product_id' => $product->id, 'quantity' => $quantity, ...$extra]);
}

function postBatchCount(StockCount $count): StockCount
{
    app(StockCountService::class)->submit($count, test()->admin);

    return app(StockCountService::class)->post($count->fresh(), test()->admin);
}

test('each batch moves by its own variance and a new batch is received', function () {
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->product->id]);
    batchEntry($count, $this->product, 8, ['product_batch_id' => $this->soon->id]);
    batchEntry($count, $this->product, 6, ['product_batch_id' => $this->late->id]);
    batchEntry($count, $this->product, 3, ['new_batch' => ['number' => 'FOUND', 'expires_at' => today()->addMonths(3)->toDateString()]]);

    $posted = postBatchCount($count);
    $movement = StockMovement::query()->where('type', StockMovementType::Opname)->sole();

    expect(countBatchBalances($this->product))->toEqual(['SOON' => 8.0, 'LATE' => 6.0, 'FOUND' => 3.0])
        ->and(outletStockQty($this->product, $this->main->id))->toBe(17.0)
        ->and((float) $movement->quantity)->toBe(1.0)
        ->and((float) $movement->batchLines->sum('quantity'))->toBe(1.0)
        ->and((float) $posted->items()->value('variance_qty'))->toBe(1.0);

    Artisan::call('stock:verify-batches');
    expect(Artisan::output())->toContain('0 selisih batch, 0 selisih total');
});

test('a sale after a batch was counted is not lost', function () {
    app(ShiftService::class)->open($this->admin, 0);
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->product->id]);
    batchEntry($count, $this->product, 9, ['product_batch_id' => $this->soon->id]);
    batchEntry($count, $this->product, 6, ['product_batch_id' => $this->late->id]);

    app(SaleService::class)->checkout($this->admin, [
        'client_uuid' => (string) Str::uuid(),
        'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'price' => 1000]],
        'payments' => [['method' => 'cash', 'amount' => 100000]],
    ]);

    postBatchCount($count);

    expect(countBatchBalances($this->product))->toEqual(['SOON' => 7.0, 'LATE' => 6.0])
        ->and(outletStockQty($this->product, $this->main->id))->toBe(13.0);
});

test('a batch sold out after counting is clamped and the rest comes out of other batches', function () {
    app(ShiftService::class)->open($this->admin, 0);
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->product->id]);
    batchEntry($count, $this->product, 4, ['product_batch_id' => $this->soon->id]);
    batchEntry($count, $this->product, 6, ['product_batch_id' => $this->late->id]);

    app(SaleService::class)->checkout($this->admin, [
        'client_uuid' => (string) Str::uuid(),
        'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'price' => 1000]],
        'payments' => [['method' => 'cash', 'amount' => 100000]],
    ]);

    $posted = postBatchCount($count);
    $item = $posted->items()->sole();

    expect(outletStockQty($this->product, $this->main->id))->toBe(0.0)
        ->and(array_sum(countBatchBalances($this->product)))->toBe(0.0)
        ->and(collect(countBatchBalances($this->product))->every(fn (float $quantity) => $quantity >= 0))->toBeTrue()
        ->and($item->hasFlag(StockCountPoster::FLAG_BATCH_SHIFTED))->toBeTrue();
});

test('counts without a batch reduce the batches that were not counted, earliest expiry first', function () {
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->product->id]);
    batchEntry($count, $this->product, 6, ['product_batch_id' => $this->late->id]);
    batchEntry($count, $this->product, 7);

    postBatchCount($count);

    expect(countBatchBalances($this->product))->toEqual(['SOON' => 7.0, 'LATE' => 6.0])
        ->and(outletStockQty($this->product, $this->main->id))->toBe(13.0);
});

test('a batch from another product is rejected', function () {
    $other = Product::factory()->create(['track_batch' => true]);
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::All);

    expect(posRejection(fn () => batchEntry($count, $other, 1, ['product_batch_id' => $this->soon->id]))->reason)->toBe('batch_not_found');
});
