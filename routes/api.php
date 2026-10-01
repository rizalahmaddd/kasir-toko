<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Private channel auth for realtime events (Reverb/Echo) with a mobile Bearer token: /api/broadcasting/auth.
Broadcast::routes(['middleware' => ['auth:sanctum']]);

// REST API for mobile and other clients. Each module file mirrors its web counterpart in routes/*.php.
Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(function () {
    require __DIR__.'/api/v1/auth.php';

    Route::middleware('auth:sanctum')->group(function () {
        require __DIR__.'/api/v1/account.php';
        require __DIR__.'/api/v1/general.php';
        require __DIR__.'/api/v1/master-data.php';
        require __DIR__.'/api/v1/pos.php';
        require __DIR__.'/api/v1/reports.php';
    });
});
