<?php

use App\Http\Controllers\Api\V1\Inventory\StockController;
use App\Http\Controllers\Api\V1\MasterData\CategoryController;
use App\Http\Controllers\Api\V1\MasterData\CustomerController;
use App\Http\Controllers\Api\V1\MasterData\ProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('master-data')->name('master-data.')->group(function () {
    Route::apiResource('customers', CustomerController::class)->middleware('feature:master-data.customers');
    Route::apiResource('categories', CategoryController::class)->middleware('feature:master-data.categories');

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
});
