<?php

use App\Enums\PaymentMethod;
use App\Enums\StockMovementType;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductOutletPrice;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Services\OutletService;
use App\Services\Pos\PosException;
use App\Services\Pos\SaleService;
use App\Services\Pos\ShiftService;
use App\Services\Pos\StockService;
use App\Services\Pos\StockTransferService;
use App\Support\CurrentOutlet;
use App\Support\OutletSettings;
use App\Support\PosSettings;
use Illuminate\Support\Str;

/**
 * Stok produk di satu outlet, dibuat langsung supaya tes tidak bergantung pada hook pembuatan produk.
 */
function stockAt(Product $product, int $outletId, float $stock): void
{
    ProductStock::query()->updateOrCreate(['product_id' => $product->id, 'outlet_id' => $outletId], ['stock' => $stock]);
    $product->forceFill(['stock' => ProductStock::query()->where('product_id', $product->id)->sum('stock')])->saveQuietly();
}

function outletStockOf(Product $product, int $outletId): float
{
    return (float) ProductStock::query()->where('product_id', $product->id)->where('outlet_id', $outletId)->value('stock');
}

/**
 * @return array<string, mixed>
 */
function outletCart(Product $product, float $quantity = 1, ?int $price = null, array $extra = []): array
{
    return [
        'client_uuid' => (string) Str::uuid(),
        'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'price' => $price ?? $product->price]],
        'payments' => [['method' => 'cash', 'amount' => 1000000]],
        ...$extra,
    ];
}

function outletRejection(callable $callback): PosException
{
    try {
        $callback();
    } catch (PosException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected the action to be rejected.');
}

beforeEach(function () {
    $this->cashier = actingAsRole('kasir');
    $this->main = primaryOutlet();
    $this->branch = makeOutlet(['name' => 'Cabang Dago', 'code' => 'DGO']);
    $this->product = Product::factory()->create(['price' => 15000, 'cost_price' => 10000, 'stock' => 0]);
    stockAt($this->product, $this->main->id, 10);
    stockAt($this->product, $this->branch->id, 4);
    $this->current = app(CurrentOutlet::class);
});

test('checkout takes stock from the outlet of the open shift and keeps the shop total in sync', function () {
    $this->current->set($this->branch);
    app(ShiftService::class)->open($this->cashier, 0);

    $sale = app(SaleService::class)->checkout($this->cashier, outletCart($this->product, 3));

    expect($sale->outlet_id)->toBe($this->branch->id)
        ->and(outletStockOf($this->product, $this->branch->id))->toBe(1.0)
        ->and(outletStockOf($this->product, $this->main->id))->toBe(10.0)
        ->and((float) $this->product->fresh()->stock)->toBe(11.0)
        ->and($sale->payments()->sole()->outlet_id)->toBe($this->branch->id);

    $movement = $this->product->stockMovements()->sole();
    expect($movement->outlet_id)->toBe($this->branch->id)
        ->and((float) $movement->stock_before)->toBe(4.0)
        ->and((float) $movement->stock_after)->toBe(1.0);
});

test('checkout rejects a sale that exceeds the stock of that outlet even when other outlets have enough', function () {
    $this->current->set($this->branch);
    app(ShiftService::class)->open($this->cashier, 0);

    $error = outletRejection(fn () => app(SaleService::class)->checkout($this->cashier, outletCart($this->product, 5)));

    expect($error->reason)->toBe('insufficient_stock')
        ->and($error->context['stock'][$this->product->id])->toBe(4.0)
        ->and(Sale::count())->toBe(0);
});

test('document numbers keep the legacy format for one outlet and carry the outlet code for several', function () {
    $branch = $this->branch;
    $branch->delete();
    $this->current->flush();

    $this->current->set($this->main);
    $shift = app(ShiftService::class)->open($this->cashier, 0);
    $first = app(SaleService::class)->checkout($this->cashier, outletCart($this->product));

    expect($shift->number)->toBe('SFT-'.now()->year.'-00001')
        ->and($first->number)->toBe('TRX-'.now()->year.'-000001');

    $second = makeOutlet(['name' => 'Cabang Baru', 'code' => 'CB2']);
    stockAt($this->product, $second->id, 5);
    app(ShiftService::class)->close($shift, $shift->summary()['expected'], $this->cashier);
    $this->current->set($second);
    $branchShift = app(ShiftService::class)->open($this->cashier, 0);
    $branchSale = app(SaleService::class)->checkout($this->cashier, outletCart($this->product));

    expect($branchShift->number)->toBe('SFT-CB2-'.now()->year.'-00001')
        ->and($branchSale->number)->toBe('TRX-CB2-'.now()->year.'-000001');

    app(ShiftService::class)->close($branchShift, $branchShift->summary()['expected'], $this->cashier);
    $this->current->set($this->main);
    app(ShiftService::class)->open($this->cashier, 0);
    $mainSale = app(SaleService::class)->checkout($this->cashier, outletCart($this->product));

    expect($mainSale->number)->toBe('TRX-PST-'.now()->year.'-000001');
});

test('voiding a sale returns the stock to the outlet where it was sold', function () {
    $this->current->set($this->branch);
    app(ShiftService::class)->open($this->cashier, 0);
    $sale = app(SaleService::class)->checkout($this->cashier, outletCart($this->product, 2));

    $admin = actingAsAdmin();
    $this->current->set($this->main);
    app(SaleService::class)->void($sale, $admin, 'salah input');

    expect(outletStockOf($this->product, $this->branch->id))->toBe(4.0)
        ->and(outletStockOf($this->product, $this->main->id))->toBe(10.0)
        ->and($sale->fresh()->isVoided())->toBeTrue();
});

test('prices, tax and payment methods follow the outlet of the sale', function () {
    OutletSettings::putMany($this->branch->id, ['pos.tax_enabled' => '1', 'pos.tax_rate' => '10', 'pos.payment_methods' => json_encode(['cash'])]);
    app(OutletService::class)->setProductPrice($this->product->id, $this->branch->id, 18000);

    $this->current->set($this->branch);
    app(ShiftService::class)->open($this->cashier, 0);

    $stale = outletRejection(fn () => app(SaleService::class)->checkout($this->cashier, outletCart($this->product, 1, 15000)));
    $declined = outletRejection(fn () => app(SaleService::class)->checkout($this->cashier, outletCart($this->product, 1, 18000, ['payments' => [['method' => 'qris', 'amount' => 19800]]])));
    $sale = app(SaleService::class)->checkout($this->cashier, outletCart($this->product, 2, 18000));

    expect($stale->reason)->toBe('price_changed')
        ->and($stale->context['prices'][$this->product->id]['price'])->toBe(18000)
        ->and($declined->getMessage())->toContain('QRIS')
        ->and($sale->subtotal)->toBe(36000)
        ->and((float) $sale->tax_rate)->toBe(10.0)
        ->and($sale->tax_amount)->toBe(3600)
        ->and($sale->items()->sole()->price)->toBe(18000);
});

test('the same product sells at the base price and without tax in an outlet that has no override', function () {
    OutletSettings::putMany($this->branch->id, ['pos.tax_enabled' => '1', 'pos.tax_rate' => '10']);

    $this->current->set($this->main);
    app(ShiftService::class)->open($this->cashier, 0);
    $sale = app(SaleService::class)->checkout($this->cashier, outletCart($this->product, 1));

    expect($sale->total)->toBe(15000)
        ->and($sale->tax_amount)->toBe(0);
});

test('a user can only keep one open shift across outlets', function () {
    $this->current->set($this->main);
    app(ShiftService::class)->open($this->cashier, 0);

    $this->current->set($this->branch);
    $error = outletRejection(fn () => app(ShiftService::class)->open($this->cashier, 0));

    expect($error->reason)->toBe('shift_other_outlet')
        ->and($error->getMessage())->toContain($this->main->name)
        ->and(CashShift::count())->toBe(1);
});

test('checkout from an outlet other than the shift outlet is refused', function () {
    $this->current->set($this->main);
    app(ShiftService::class)->open($this->cashier, 0);

    $error = outletRejection(fn () => app(SaleService::class)->checkout($this->cashier, outletCart($this->product, 1, null, ['outlet_id' => $this->branch->id])));

    $this->current->set($this->branch);
    $switched = outletRejection(fn () => app(SaleService::class)->checkout($this->cashier, outletCart($this->product)));

    expect($error->reason)->toBe('outlet_mismatch')
        ->and($switched->reason)->toBe('outlet_mismatch');
});

test('a plan-locked outlet refuses new shifts and online checkout but still accepts queued offline sales', function () {
    $this->current->set($this->branch);
    $shift = app(ShiftService::class)->open($this->cashier, 0);

    $this->tenant->update(['plan' => 'free']);
    $this->current->flush();

    $online = outletRejection(fn () => app(SaleService::class)->checkout($this->cashier, outletCart($this->product)));
    $offline = app(SaleService::class)->checkout($this->cashier, outletCart($this->product, 1, null, ['offline' => true]));

    app(ShiftService::class)->close($shift, $shift->summary()['expected'], $this->cashier);
    $newShift = outletRejection(fn () => app(ShiftService::class)->open($this->cashier, 0));

    expect($online->reason)->toBe('outlet_locked')
        ->and($offline->outlet_id)->toBe($this->branch->id)
        ->and($newShift->reason)->toBe('outlet_locked');
});

test('stock adjustments and opname work on the stock of the selected outlet only', function () {
    $admin = actingAsAdmin();
    $this->current->set($this->branch);

    app(StockService::class)->adjust($this->product, StockMovementType::Opname, 6, $admin, 'hitung ulang');
    app(StockService::class)->adjust($this->product, StockMovementType::StockIn, 5, $admin, null, 8000);

    expect(outletStockOf($this->product, $this->branch->id))->toBe(11.0)
        ->and(outletStockOf($this->product, $this->main->id))->toBe(10.0)
        ->and((float) $this->product->fresh()->stock)->toBe(21.0)
        // HPP rata-rata dari stok total semua outlet (satu HPP per produk).
        ->and($this->product->fresh()->cost_price)->toBe((int) round((16 * 10000 + 5 * 8000) / 21));
});

test('a stock transfer moves stock between outlets without changing the shop total', function () {
    $admin = actingAsAdmin();

    $transfer = app(StockTransferService::class)->create($admin, $this->main->id, $this->branch->id, [['product_id' => $this->product->id, 'quantity' => 3]], 'restock');

    expect($transfer->number)->toStartWith('TRF-PST-')
        ->and(outletStockOf($this->product, $this->main->id))->toBe(7.0)
        ->and(outletStockOf($this->product, $this->branch->id))->toBe(7.0)
        ->and((float) $this->product->fresh()->stock)->toBe(14.0)
        ->and($this->product->stockMovements()->where('type', StockMovementType::TransferOut->value)->sole()->outlet_id)->toBe($this->main->id)
        ->and($this->product->stockMovements()->where('type', StockMovementType::TransferIn->value)->sole()->outlet_id)->toBe($this->branch->id);

    app(StockTransferService::class)->cancel($transfer, $admin);

    expect(outletStockOf($this->product, $this->main->id))->toBe(10.0)
        ->and(outletStockOf($this->product, $this->branch->id))->toBe(4.0)
        ->and($transfer->fresh()->isCancelled())->toBeTrue();
});

test('a transfer is refused for missing stock, the same outlet, or untracked products', function () {
    $admin = actingAsAdmin();
    $untracked = Product::factory()->untracked()->create();

    expect(outletRejection(fn () => app(StockTransferService::class)->create($admin, $this->main->id, $this->branch->id, [['product_id' => $this->product->id, 'quantity' => 11]]))->getMessage())->toContain('tidak cukup')
        ->and(outletRejection(fn () => app(StockTransferService::class)->create($admin, $this->main->id, $this->main->id, [['product_id' => $this->product->id, 'quantity' => 1]]))->getMessage())->toContain('tidak boleh sama')
        ->and(outletRejection(fn () => app(StockTransferService::class)->create($admin, $this->main->id, $this->branch->id, [['product_id' => $untracked->id, 'quantity' => 1]]))->getMessage())->toContain('dilacak')
        ->and(outletStockOf($this->product, $this->main->id))->toBe(10.0);
});

test('a transfer cannot be cancelled once the receiving outlet sold the stock', function () {
    $admin = actingAsAdmin();
    $transfer = app(StockTransferService::class)->create($admin, $this->main->id, $this->branch->id, [['product_id' => $this->product->id, 'quantity' => 3]]);
    stockAt($this->product, $this->branch->id, 1);

    $error = outletRejection(fn () => app(StockTransferService::class)->cancel($transfer, $admin));

    expect($error->getMessage())->toContain('sudah tidak cukup')
        ->and($transfer->fresh()->isCancelled())->toBeFalse()
        ->and(outletStockOf($this->product, $this->main->id))->toBe(7.0);
});

test('a credit payment received at another outlet lands in the drawer of the receiving shift', function () {
    $customer = Customer::factory()->create();
    $this->current->set($this->main);
    $mainShift = app(ShiftService::class)->open($this->cashier, 0);
    $sale = app(SaleService::class)->checkout($this->cashier, outletCart($this->product, 2, null, [
        'customer_id' => $customer->id,
        'payments' => [['method' => 'cash', 'amount' => 10000]],
    ]));
    app(ShiftService::class)->close($mainShift, $mainShift->summary()['expected'], $this->cashier);

    $clerk = User::factory()->create();
    $clerk->assignRole(seededRole('kasir'));
    $this->current->set($this->branch);
    $branchShift = app(ShiftService::class)->open($clerk, 0);
    $payment = app(SaleService::class)->payReceivable($sale, $clerk, 20000, PaymentMethod::Cash);

    expect($payment->outlet_id)->toBe($this->branch->id)
        ->and($payment->cash_shift_id)->toBe($branchShift->id)
        ->and($sale->fresh()->outlet_id)->toBe($this->main->id)
        ->and($sale->fresh()->due_amount)->toBe(0)
        ->and($branchShift->fresh()->summary()['cash_receivables'])->toBe(20000);
});

test('products get stock rows for every outlet and shops without models events lazily inherit their stock', function () {
    $fresh = Product::factory()->create(['stock' => 7]);

    expect(ProductStock::query()->where('product_id', $fresh->id)->count())->toBe(2)
        ->and(outletStockOf($fresh, $this->main->id))->toBe(7.0)
        ->and(outletStockOf($fresh, $this->branch->id))->toBe(0.0);

    ProductStock::query()->where('product_id', $fresh->id)->delete();
    $row = app(StockService::class)->lockStock($fresh, $this->main->id);

    expect((float) $row->stock)->toBe(7.0);
});

test('outlet settings fall back to the shop value until overridden and can be cleared again', function () {
    Setting::put('pos.receipt_footer', 'Terima kasih');
    OutletSettings::put($this->branch->id, 'pos.receipt_footer', 'Cabang Dago buka 24 jam');

    expect($this->current->run($this->main, fn () => PosSettings::get('pos.receipt_footer')))->toBe('Terima kasih')
        ->and($this->current->run($this->branch, fn () => PosSettings::get('pos.receipt_footer')))->toBe('Cabang Dago buka 24 jam');

    OutletSettings::put($this->branch->id, 'pos.receipt_footer', null);

    expect($this->current->run($this->branch, fn () => PosSettings::get('pos.receipt_footer')))->toBe('Terima kasih')
        ->and(ProductOutletPrice::query()->count())->toBe(0);
});
