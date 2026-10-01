<?php

use App\Http\Controllers\Api\V1\Pos\CashierController;
use App\Http\Controllers\Api\V1\Pos\CurrentShiftController;
use App\Http\Controllers\Api\V1\Pos\HeldOrderController;
use App\Http\Controllers\Api\V1\PrintController;
use App\Http\Controllers\Api\V1\Sales\ReceivableController;
use App\Http\Controllers\Api\V1\Sales\SaleController;
use App\Http\Controllers\Api\V1\Sales\ShiftController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:pos.sell')->group(function () {
    Route::prefix('pos')->name('pos.')->middleware('feature:pos.cashier')->group(function () {
        Route::get('config', [CashierController::class, 'config'])->name('config');
        Route::get('categories', [CashierController::class, 'categories'])->name('categories');
        Route::get('products', [CashierController::class, 'products'])->name('products');
        Route::get('products/lookup', [CashierController::class, 'lookup'])->name('products.lookup');
        Route::get('customers', [CashierController::class, 'customers'])->name('customers.index');
        Route::post('customers', [CashierController::class, 'storeCustomer'])->name('customers.store');
        Route::get('qris', [CashierController::class, 'qris'])->name('qris');
        Route::post('checkout', [CashierController::class, 'checkout'])->middleware('throttle:60,1')->name('checkout');

        Route::get('shift', [CurrentShiftController::class, 'show'])->name('shift.show');
        Route::post('shift', [CurrentShiftController::class, 'open'])->name('shift.open');
        Route::post('shift/cash-movements', [CurrentShiftController::class, 'recordCash'])->name('shift.cash-movements.store');

        Route::get('held-orders', [HeldOrderController::class, 'index'])->name('held-orders.index');
        Route::post('held-orders', [HeldOrderController::class, 'store'])->name('held-orders.store');
        Route::post('held-orders/{heldOrder}/resume', [HeldOrderController::class, 'resume'])->name('held-orders.resume');
        Route::delete('held-orders/{heldOrder}', [HeldOrderController::class, 'destroy'])->name('held-orders.destroy');
    });

    Route::prefix('sales')->name('sales.')->middleware('feature:pos.sales')->group(function () {
        Route::get('/', [SaleController::class, 'index'])->name('index');
        Route::get('{sale}', [SaleController::class, 'show'])->name('show');
        Route::post('{sale}/void', [SaleController::class, 'void'])->name('void');
        Route::get('{sale}/receipt', [SaleController::class, 'receipt'])->name('receipt');
    });

    Route::prefix('shifts')->name('shifts.')->middleware('feature:pos.shifts')->group(function () {
        Route::get('/', [ShiftController::class, 'index'])->name('index');
        Route::get('{cashShift}', [ShiftController::class, 'show'])->name('show');
        Route::get('{cashShift}/sales', [ShiftController::class, 'sales'])->name('sales');
        Route::post('{cashShift}/close', [ShiftController::class, 'close'])->name('close');
        Route::post('{cashShift}/cash-movements', [ShiftController::class, 'recordCash'])->name('cash-movements.store');
    });

    Route::prefix('print')->name('print.')->group(function () {
        Route::get('receipt/{sale}', [PrintController::class, 'receipt'])->middleware('feature:pos.cashier')->name('receipt');
        Route::get('shift/{cashShift}', [PrintController::class, 'shift'])->middleware('feature:pos.shifts')->name('shift');
    });
});

Route::prefix('receivables')->name('receivables.')->middleware(['can:receivables.manage', 'feature:pos.receivables'])->group(function () {
    Route::get('/', [ReceivableController::class, 'index'])->name('index');
    Route::post('{sale}/payments', [ReceivableController::class, 'pay'])->name('payments.store');
});
