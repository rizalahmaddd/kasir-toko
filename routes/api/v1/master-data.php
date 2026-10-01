<?php

use App\Http\Controllers\Api\V1\MasterData\CustomerController;
use Illuminate\Support\Facades\Route;

Route::prefix('master-data')->name('master-data.')->group(function () {
    Route::apiResource('customers', CustomerController::class)->middleware('feature:master-data.customers');
});
