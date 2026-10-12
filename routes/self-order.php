<?php

use App\Http\Controllers\SelfOrderController;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureStoreOnboarded;
use App\Http\Middleware\EnsureTenantAccess;
use App\Http\Middleware\IdentifyOutlet;
use App\Http\Middleware\IdentifySelfOrderContext;
use App\Http\Middleware\IdentifyTenant;
use Illuminate\Support\Facades\Route;

// Halaman pesanan QR pelanggan, tanpa login. Middleware toko milik sesi login dilepas: konteks tenant dan
// outlet WAJIB datang dari token di URL (IdentifySelfOrderContext), bukan dari siapa pun yang kebetulan login.
Route::middleware([IdentifySelfOrderContext::class, 'throttle:self-order'])
    ->withoutMiddleware([IdentifyTenant::class, IdentifyOutlet::class, EnsureTenantAccess::class, EnsureStoreOnboarded::class, EnsureFeatureEnabled::class])
    ->prefix('pesan')->name('self-order.')->group(function () {
        Route::get('m/{tableToken}', [SelfOrderController::class, 'table'])->name('table');
        Route::get('o/{outletToken}', [SelfOrderController::class, 'outlet'])->name('outlet');

        Route::get('status/{sessionToken}', [SelfOrderController::class, 'status'])->name('status');
        Route::get('status/{sessionToken}/state', [SelfOrderController::class, 'state'])->name('state');
        Route::get('status/{sessionToken}/qris', [SelfOrderController::class, 'qris'])->name('qris');
        Route::post('status/{sessionToken}/cancel', [SelfOrderController::class, 'cancel'])->name('cancel');

        Route::get('{token}/menu', [SelfOrderController::class, 'menu'])->name('menu');
        Route::post('{token}/quote', [SelfOrderController::class, 'quote'])->name('quote');
        Route::post('{token}/order', [SelfOrderController::class, 'store'])->middleware('throttle:self-order-submit')->name('order');
    });
