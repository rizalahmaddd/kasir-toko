<?php

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Livewire\Sales\SaleShow;
use App\Livewire\Sales\ShiftShow;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Services\Pos\PosException;
use App\Services\Pos\SaleService;
use App\Services\Pos\ShiftService;
use App\Services\Pos\StockService;
use Illuminate\Support\Str;
use Livewire\Livewire;

function sellFor(User $cashier, Product $product, float $quantity, array $payments, ?Customer $customer = null)
{
    return app(SaleService::class)->checkout($cashier, [
        'client_uuid' => (string) Str::uuid(),
        'customer_id' => $customer?->id,
        'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'price' => $product->price]],
        'payments' => $payments,
    ]);
}

beforeEach(function () {
    $this->admin = actingAsAdmin();
    $this->product = Product::factory()->create(['price' => 10000, 'cost_price' => 6000, 'stock' => 20]);
    $this->shift = app(ShiftService::class)->open($this->admin, 50000);
});

test('voiding restores stock and drops the sale from the open shift', function () {
    $sale = sellFor($this->admin, $this->product, 3, [['method' => 'cash', 'amount' => 30000]]);
    expect($this->shift->summary()['expected'])->toBe(80000);

    Livewire::test(SaleShow::class, ['sale' => $sale])
        ->set('voidReason', 'Salah input barang')
        ->call('void')
        ->assertHasNoErrors();

    expect($sale->fresh()->status)->toBe(SaleStatus::Voided)
        ->and((float) $this->product->fresh()->stock)->toBe(20.0)
        ->and($this->product->stockMovements()->latest('id')->first()->type)->toBe(StockMovementType::SaleVoid)
        ->and($this->shift->summary()['expected'])->toBe(50000)
        ->and($this->shift->summary()['voided_count'])->toBe(1);
});

test('voiding a sale from a closed shift refunds cash from the current shift', function () {
    $sale = sellFor($this->admin, $this->product, 2, [['method' => 'cash', 'amount' => 20000]]);
    app(ShiftService::class)->close($this->shift, 70000, $this->admin);

    expect(fn () => app(SaleService::class)->void($sale, $this->admin, 'Retur'))->toThrow(PosException::class);

    $current = app(ShiftService::class)->open($this->admin, 0);
    app(SaleService::class)->void($sale, $this->admin, 'Retur');

    $refund = $current->cashMovements()->sole();
    expect($refund->type)->toBe(CashMovementType::Out)
        ->and($refund->amount)->toBe(20000)
        ->and($this->shift->fresh()->expected_cash)->toBe(70000);
});

test('voiding a credit sale only refunds cash taken in closed shifts', function () {
    $customer = Customer::factory()->create();
    $sale = sellFor($this->admin, $this->product, 2, [['method' => 'cash', 'amount' => 5000]], $customer);
    app(ShiftService::class)->close($this->shift, 55000, $this->admin);

    $current = app(ShiftService::class)->open($this->admin, 0);
    app(SaleService::class)->payReceivable($sale, $this->admin, 15000, PaymentMethod::Cash);
    expect($current->summary()['expected'])->toBe(15000);

    app(SaleService::class)->void($sale, $this->admin, 'Retur');

    expect($current->cashMovements()->sole()->amount)->toBe(5000)
        ->and($current->summary()['expected'])->toBe(-5000);
});

test('a sale cannot be voided twice or without permission', function () {
    $sale = sellFor($this->admin, $this->product, 1, [['method' => 'cash', 'amount' => 10000]]);
    app(SaleService::class)->void($sale, $this->admin, 'Batal');

    expect(fn () => app(SaleService::class)->void($sale, $this->admin, 'Lagi'))->toThrow(PosException::class, 'sudah dibatalkan');

    $cashier = actingAsRole('kasir');
    app(ShiftService::class)->open($cashier, 0);
    $own = sellFor($cashier, $this->product, 1, [['method' => 'cash', 'amount' => 10000]]);

    Livewire::test(SaleShow::class, ['sale' => $own])->call('void')->assertForbidden();
});

test('cashiers only see their own sales', function () {
    $adminSale = sellFor($this->admin, $this->product, 1, [['method' => 'cash', 'amount' => 10000]]);
    $cashier = actingAsRole('kasir');

    $this->get(route('sales.show', $adminSale))->assertForbidden();
    $this->get(route('pos.receipt', $adminSale))->assertForbidden();
    $this->get(route('sales.index'))->assertOk()->assertDontSee($adminSale->number);
});

test('closing a shift stores the expected cash and the difference', function () {
    sellFor($this->admin, $this->product, 2, [['method' => 'cash', 'amount' => 50000]]);
    sellFor($this->admin, $this->product, 1, [['method' => 'qris', 'amount' => 10000]]);
    app(ShiftService::class)->recordCash($this->shift, CashMovementType::Out, 5000, 'Beli plastik', $this->admin);

    Livewire::test(ShiftShow::class, ['cashShift' => $this->shift])
        ->set('countedCash', '60000')
        ->call('close')
        ->assertHasErrors('closingNote')
        ->set('closingNote', 'Uang receh hilang')
        ->call('close')
        ->assertHasNoErrors();

    $shift = $this->shift->fresh();
    expect($shift->isOpen())->toBeFalse()
        ->and($shift->expected_cash)->toBe(65000)
        ->and($shift->cash_difference)->toBe(-5000);

    expect(fn () => app(ShiftService::class)->recordCash($shift, CashMovementType::In, 1000, 'x', $this->admin))->toThrow(PosException::class);
});

test('opening a shift twice returns the same shift', function () {
    expect(app(ShiftService::class)->open($this->admin, 999)->id)->toBe($this->shift->id);
});

test('cash out cannot exceed the cash in the drawer', function () {
    expect(fn () => app(ShiftService::class)->recordCash($this->shift, CashMovementType::Out, 60000, 'Setor', $this->admin))
        ->toThrow(PosException::class);
});

test('credit is paid off in parts and cash goes to the open shift', function () {
    $customer = Customer::factory()->create();
    $sale = sellFor($this->admin, $this->product, 3, [['method' => 'cash', 'amount' => 10000]], $customer);
    expect($sale->due_amount)->toBe(20000);

    $service = app(SaleService::class);
    expect(fn () => $service->payReceivable($sale, $this->admin, 25000, PaymentMethod::Cash))->toThrow(PosException::class, 'melebihi');

    $service->payReceivable($sale, $this->admin, 5000, PaymentMethod::Cash);
    $service->payReceivable($sale, $this->admin, 15000, PaymentMethod::Transfer, 'TRF-1');

    expect($sale->fresh()->due_amount)->toBe(0)
        ->and($sale->fresh()->paid_amount)->toBe(30000)
        ->and($this->shift->summary()['cash_receivables'])->toBe(5000)
        ->and($this->shift->summary()['expected'])->toBe(65000);

    expect(fn () => $service->payReceivable($sale, $this->admin, 1, PaymentMethod::Cash))->toThrow(PosException::class, 'lunas');
});

test('cash credit payments need an open shift', function () {
    $customer = Customer::factory()->create();
    $sale = sellFor($this->admin, $this->product, 2, [], $customer);
    app(ShiftService::class)->close($this->shift, 50000, $this->admin);

    expect(fn () => app(SaleService::class)->payReceivable($sale, $this->admin, 1000, PaymentMethod::Cash))->toThrow(PosException::class, 'shift');

    app(SaleService::class)->payReceivable($sale, $this->admin, 1000, PaymentMethod::Qris);
    expect($sale->fresh()->due_amount)->toBe(19000);
});

test('stock in updates the average cost, opname records the difference', function () {
    $stock = app(StockService::class);

    $stock->adjust($this->product, StockMovementType::StockIn, 20, $this->admin, 'Dari grosir', 9000);
    $product = $this->product->fresh();
    expect((float) $product->stock)->toBe(40.0)
        ->and($product->cost_price)->toBe(7500);

    $movement = $stock->adjust($product, StockMovementType::Opname, 37, $this->admin);
    expect((float) $movement->quantity)->toBe(-3.0)
        ->and((float) $product->fresh()->stock)->toBe(37.0);

    $stock->adjust($product, StockMovementType::StockOut, 2, $this->admin, 'Rusak');
    expect((float) $product->fresh()->stock)->toBe(35.0);

    expect(fn () => $stock->adjust($product->fresh(), StockMovementType::Opname, 35, $this->admin))->toThrow(PosException::class);
    expect(fn () => $stock->adjust(Product::factory()->untracked()->create(), StockMovementType::StockIn, 1, $this->admin))->toThrow(PosException::class);
});
