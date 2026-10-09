<?php

use App\Http\Controllers\Api\V1\Inventory\StockController;
use App\Http\Controllers\Api\V1\Inventory\StockCountController;
use App\Http\Controllers\Api\V1\Inventory\StockTransferController;
use App\Http\Controllers\Api\V1\MasterData\CategoryController;
use App\Http\Controllers\Api\V1\MasterData\CustomerController;
use App\Http\Controllers\Api\V1\MasterData\ModifierGroupController;
use App\Http\Controllers\Api\V1\MasterData\ProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('master-data')->name('master-data.')->group(function () {
    Route::apiResource('customers', CustomerController::class)->middleware('feature:master-data.customers');
    Route::apiResource('categories', CategoryController::class)->middleware('feature:master-data.categories');
    Route::apiResource('modifier-groups', ModifierGroupController::class)->middleware('feature:business.modifiers');

    Route::middleware('feature:master-data.products')->group(function () {
        Route::apiResource('products', ProductController::class);
        Route::post('products/{product}/image', [ProductController::class, 'uploadImage'])->name('products.image.store');
        Route::delete('products/{product}/image', [ProductController::class, 'deleteImage'])->name('products.image.destroy');
    });
});

Route::prefix('inventory')->name('inventory.')->middleware('feature:inventory.stock')->group(function () {
    Route::get('stock', [StockController::class, 'index'])->name('stock.index');
    Route::get('stock/summary', [StockController::class, 'summary'])->name('stock.summary');
    Route::get('movements', [StockController::class, 'movements'])->name('movements.index');
    Route::post('adjustments', [StockController::class, 'adjust'])->name('adjustments.store');
    Route::post('batch-opname', [StockController::class, 'batchOpname'])->middleware('feature:business.batch-expiry')->name('batch-opname.store');
    Route::get('products/{product}/batches', [StockController::class, 'batches'])->middleware('feature:business.batch-expiry')->name('batches.index');
    Route::get('expiring', [StockController::class, 'expiring'])->middleware(['feature:business.batch-expiry', 'pro'])->name('expiring.index');

    Route::middleware('feature:inventory.opname')->prefix('stock-counts')->name('stock-counts.')->group(function () {
        Route::get('/', [StockCountController::class, 'index'])->name('index');
        Route::post('/', [StockCountController::class, 'store'])->name('store');
        Route::get('{stockCount}', [StockCountController::class, 'show'])->name('show');
        Route::get('{stockCount}/items', [StockCountController::class, 'items'])->name('items.index');
        Route::post('{stockCount}/items', [StockCountController::class, 'addItems'])->name('items.store');
        Route::patch('{stockCount}/items/{item}', [StockCountController::class, 'updateItem'])->name('items.update');
        Route::get('{stockCount}/items/{item}/entries', [StockCountController::class, 'entries'])->name('items.entries');
        Route::get('{stockCount}/catalog', [StockCountController::class, 'catalog'])->name('catalog');
        Route::get('{stockCount}/lookup', [StockCountController::class, 'lookup'])->name('lookup');
        Route::post('{stockCount}/entries', [StockCountController::class, 'storeEntries'])->name('entries.store');
        Route::delete('{stockCount}/entries/{entry}', [StockCountController::class, 'voidEntry'])->name('entries.destroy');
        Route::post('{stockCount}/serials', [StockCountController::class, 'storeSerials'])->name('serials.store');
        Route::post('{stockCount}/unknown', [StockCountController::class, 'storeUnknown'])->name('unknown.store');
        Route::post('{stockCount}/submit', [StockCountController::class, 'submit'])->name('submit');
        Route::post('{stockCount}/reopen', [StockCountController::class, 'reopen'])->name('reopen');
        Route::get('{stockCount}/preview', [StockCountController::class, 'preview'])->name('preview');
        Route::post('{stockCount}/post', [StockCountController::class, 'post'])->name('post');
        Route::post('{stockCount}/cancel', [StockCountController::class, 'cancel'])->name('cancel');
    });

    Route::middleware('feature:inventory.transfer')->prefix('transfers')->name('transfers.')->group(function () {
        Route::get('/', [StockTransferController::class, 'index'])->name('index');
        Route::post('/', [StockTransferController::class, 'store'])->name('store');
        Route::get('{transfer}', [StockTransferController::class, 'show'])->name('show');
        Route::post('{transfer}/cancel', [StockTransferController::class, 'cancel'])->name('cancel');
    });
});
