<?php

use App\Enums\StockCountScope;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Services\OutletService;
use App\Services\Pos\SaleService;
use App\Services\Pos\ShiftService;
use App\Services\Pos\StockCountService;
use App\Services\Pos\StockCountVariance;
use App\Services\Pos\StockService;
use App\Services\Pos\StockTransferService;
use App\Support\Features;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->admin = actingAsAdmin();
    $this->main = primaryOutlet();
    $this->branch = makeOutlet(['code' => 'CB1']);
    $this->counts = app(StockCountService::class);
    $this->stock = app(StockService::class);
    $this->product = Product::factory()->create();
    setOutletStock($this->product, $this->main->id, 10);
    setOutletStock($this->product, $this->branch->id, 5);
});

test('holding adjustments blocks stock in, stock out, quick opname and transfers but never sales', function () {
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::All);

    $stockIn = posRejection(fn () => $this->stock->adjust($this->product, StockMovementType::StockIn, 2, $this->admin, outletId: $this->main->id));
    $quick = posRejection(fn () => $this->stock->adjust($this->product, StockMovementType::Opname, 8, $this->admin, outletId: $this->main->id));
    $transferIn = posRejection(fn () => app(StockTransferService::class)->create($this->admin, $this->branch->id, $this->main->id, [['product_id' => $this->product->id, 'quantity' => 1]]));

    expect($stockIn->reason)->toBe('stock_count_in_progress')
        ->and($stockIn->getMessage())->toContain($count->number)
        ->and($stockIn->context['stock_count_id'])->toBe($count->id)
        ->and($quick->reason)->toBe('stock_count_in_progress')
        ->and($transferIn->reason)->toBe('stock_count_in_progress');

    app(ShiftService::class)->open($this->admin, 0, $this->main->id);
    $sale = app(SaleService::class)->checkout($this->admin, [
        'client_uuid' => (string) Str::uuid(),
        'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'price' => $this->product->price]],
        'payments' => [['method' => 'cash', 'amount' => 1000000]],
    ]);
    app(SaleService::class)->void($sale, $this->admin, 'Salah input');

    $this->stock->adjust($this->product, StockMovementType::StockIn, 2, $this->admin, outletId: $this->branch->id);

    expect(outletStockQty($this->product, $this->main->id))->toBe(10.0)
        ->and(outletStockQty($this->product, $this->branch->id))->toBe(7.0);
});

test('the hold only covers products in a picked-products count and ends when the count closes', function () {
    $other = Product::factory()->create();
    setOutletStock($other, $this->main->id, 3);
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->product->id], holdAdjustments: true);

    $this->stock->adjust($other, StockMovementType::StockOut, 1, $this->admin, outletId: $this->main->id);
    expect(posRejection(fn () => $this->stock->adjust($this->product, StockMovementType::StockOut, 1, $this->admin, outletId: $this->main->id))->reason)->toBe('stock_count_in_progress');

    $this->counts->cancel($count, $this->admin);
    $this->stock->adjust($this->product, StockMovementType::StockOut, 1, $this->admin, outletId: $this->main->id);

    expect(outletStockQty($this->product, $this->main->id))->toBe(9.0)
        ->and(outletStockQty($other, $this->main->id))->toBe(2.0);
});

test('without the hold adjustments go through and are flagged when they happen after counting', function () {
    $count = $this->counts->start($this->admin, $this->main->id, StockCountScope::Products, [], [$this->product->id]);
    $this->counts->recordEntry($count, $this->admin, ['client_uuid' => (string) Str::uuid(), 'product_id' => $this->product->id, 'quantity' => 10]);

    $this->travel(5)->minutes();
    $this->stock->adjust($this->product, StockMovementType::StockIn, 4, $this->admin, outletId: $this->main->id);
    $this->counts->preview($count);

    expect(outletStockQty($this->product, $this->main->id))->toBe(14.0)
        ->and($count->items()->first()->hasFlag(StockCountVariance::FLAG_MANUAL_MOVEMENT))->toBeTrue();
});

test('a switched-off stock count feature never locks stock', function () {
    $this->counts->start($this->admin, $this->main->id, StockCountScope::All);
    Features::setDisabled(['inventory.opname']);

    $this->stock->adjust($this->product, StockMovementType::StockIn, 2, $this->admin, outletId: $this->main->id);

    expect(outletStockQty($this->product, $this->main->id))->toBe(12.0);
});

test('an outlet with a running stock count cannot be deactivated', function () {
    $count = $this->counts->start($this->admin, $this->branch->id, StockCountScope::All);

    expect(fn () => app(OutletService::class)->deactivate($this->branch, $this->admin))
        ->toThrow(ValidationException::class, $count->number);

    $this->counts->cancel($count, $this->admin);
    app(OutletService::class)->deactivate($this->branch, $this->admin);

    expect($this->branch->fresh()->is_active)->toBeFalse();
});
