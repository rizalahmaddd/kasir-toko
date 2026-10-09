<?php

use App\Http\Controllers\Api\V1\Orders\CustomerOrderController;
use App\Http\Controllers\Api\V1\Sales\DeliveryNoteController;
use Illuminate\Support\Facades\Route;

Route::prefix('orders')->name('orders.')->middleware('feature:business.pre-order')->group(function () {
    Route::get('/', [CustomerOrderController::class, 'index'])->name('index');
    Route::post('/', [CustomerOrderController::class, 'store'])->name('store');
    Route::get('{order}', [CustomerOrderController::class, 'show'])->name('show');
    Route::post('{order}/payments', [CustomerOrderController::class, 'pay'])->name('payments.store');
    Route::put('{order}/status', [CustomerOrderController::class, 'status'])->name('status');
    Route::post('{order}/cancel', [CustomerOrderController::class, 'cancel'])->name('cancel');
    Route::get('{order}/cart', [CustomerOrderController::class, 'cart'])->name('cart');
});

Route::middleware(['can:pos.sell', 'feature:business.delivery-note'])->group(function () {
    Route::post('sales/{sale}/delivery-notes', [DeliveryNoteController::class, 'store'])->name('delivery-notes.store');
    Route::post('delivery-notes/{note}/delivered', [DeliveryNoteController::class, 'delivered'])->name('delivery-notes.delivered');
});
