<?php

use App\Http\Controllers\Api\AndroidReleaseUploadController;
use App\Http\Controllers\Api\SumoPodWebhookController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Private channel auth for realtime events (Reverb/Echo) with a mobile Bearer token: /api/broadcasting/auth.
Broadcast::routes(['middleware' => ['auth:sanctum']]);

// Webhook SumoPod Managed Payment (pembayaran langganan QRIS)
Route::post('webhooks/sumopod', SumoPodWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhooks.sumopod');

// Called by the kasir-toko-mobile release workflow; guarded by APP_RELEASE_UPLOAD_TOKEN.
Route::prefix('app-releases/android')->name('app-releases.android.')->middleware('throttle:30,1')->group(function () {
    Route::post('/', [AndroidReleaseUploadController::class, 'store'])->name('store');
    Route::post('publish', [AndroidReleaseUploadController::class, 'publish'])->name('publish');
});

// REST API for mobile and other clients. Each module file mirrors its web counterpart in routes/*.php.
Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(function () {
    require __DIR__.'/api/v1/auth.php';

    Route::middleware('auth:sanctum')->group(function () {
        require __DIR__.'/api/v1/account.php';
        require __DIR__.'/api/v1/outlets.php';
        require __DIR__.'/api/v1/onboarding.php';
        require __DIR__.'/api/v1/general.php';
        require __DIR__.'/api/v1/master-data.php';
        require __DIR__.'/api/v1/pos.php';
        require __DIR__.'/api/v1/pharmacy.php';
        require __DIR__.'/api/v1/orders.php';
        require __DIR__.'/api/v1/reports.php';
    });
});
