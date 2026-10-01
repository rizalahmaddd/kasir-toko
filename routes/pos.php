<?php

use App\Http\Controllers\CustomerDisplayController;
use App\Http\Controllers\PosCheckoutController;
use App\Http\Controllers\PrintController;
use App\Livewire\Pos\Cashier;
use App\Livewire\Sales\Receivables;
use App\Livewire\Sales\SaleIndex;
use App\Livewire\Sales\SaleShow;
use App\Livewire\Sales\ShiftIndex;
use App\Livewire\Sales\ShiftShow;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'can:pos.sell'])->group(function () {
    Route::get('kasir', Cashier::class)->name('pos.cashier');
    Route::post('kasir/checkout', PosCheckoutController::class)->middleware('throttle:60,1')->name('pos.checkout');
    Route::post('kasir/layar-pelanggan', [CustomerDisplayController::class, 'push'])->middleware('throttle:display')->name('pos.display.push');
    Route::get('kasir/struk/{sale}', [PrintController::class, 'receipt'])->name('pos.receipt');

    Route::get('penjualan', SaleIndex::class)->name('sales.index');
    Route::get('penjualan/{sale}', SaleShow::class)->name('sales.show');

    Route::get('shift', ShiftIndex::class)->name('shifts.index');
    Route::get('shift/{cashShift}', ShiftShow::class)->name('shifts.show');
    Route::get('shift/{cashShift}/cetak', [PrintController::class, 'shift'])->name('shifts.print');
});

Route::middleware(['auth', 'verified', 'can:receivables.manage'])->group(function () {
    Route::get('piutang', Receivables::class)->name('receivables.index');
});

// Layar pelanggan dibuka tanpa login; aksesnya lewat kunci rahasia di URL (lihat User::displayKey()).
Route::middleware('throttle:display')->prefix('layar')->name('display.')->group(function () {
    Route::get('{key}', [CustomerDisplayController::class, 'show'])->name('show');
    Route::get('{key}/state', [CustomerDisplayController::class, 'state'])->name('state');
    Route::get('{key}/qris', [CustomerDisplayController::class, 'qris'])->name('qris');
});
