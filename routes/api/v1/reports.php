<?php

use App\Http\Controllers\Api\V1\Reports\ActivityLogController;
use App\Http\Controllers\Api\V1\Reports\SalesReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('reports')->name('reports.')->group(function () {
    Route::prefix('sales')->name('sales.')->middleware('feature:reports.sales')->group(function () {
        Route::get('summary', [SalesReportController::class, 'summary'])->name('summary');
        Route::get('daily', [SalesReportController::class, 'daily'])->name('daily');
        Route::get('products', [SalesReportController::class, 'products'])->name('products');
    });

    Route::get('activity-log', [ActivityLogController::class, 'index'])->middleware('feature:reports.activity-log')->name('activity-log');
});
