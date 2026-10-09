<?php

use App\Http\Controllers\Api\V1\Outlets\CurrentOutletController;
use App\Http\Controllers\Api\V1\Outlets\OutletController;
use Illuminate\Support\Facades\Route;

Route::put('auth/current-outlet', CurrentOutletController::class)->name('auth.current-outlet');

Route::prefix('outlets')->name('outlets.')->middleware('feature:settings.outlets')->group(function () {
    Route::get('/', [OutletController::class, 'index'])->name('index');
    Route::put('priorities', [OutletController::class, 'priorities'])->name('priorities');
    Route::post('/', [OutletController::class, 'store'])->name('store');
    Route::get('{outlet}', [OutletController::class, 'show'])->name('show');
    Route::put('{outlet}', [OutletController::class, 'update'])->name('update');
    Route::delete('{outlet}', [OutletController::class, 'destroy'])->name('destroy');
    Route::post('{outlet}/primary', [OutletController::class, 'primary'])->name('primary');
    Route::put('{outlet}/active', [OutletController::class, 'active'])->name('active');
    Route::get('{outlet}/users', [OutletController::class, 'accessList'])->name('users.index');
    Route::put('{outlet}/users', [OutletController::class, 'users'])->name('users');
    Route::post('{outlet}/copy', [OutletController::class, 'copy'])->name('copy');
    Route::get('{outlet}/settings', [OutletController::class, 'settings'])->name('settings');
    Route::put('{outlet}/settings', [OutletController::class, 'updateSettings'])->name('settings.update');
    Route::put('{outlet}/capabilities', [OutletController::class, 'updateCapabilities'])->name('capabilities.update');
});
