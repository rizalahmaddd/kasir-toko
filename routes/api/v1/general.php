<?php

use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', DashboardController::class)->name('dashboard');
Route::get('meta', [MetaController::class, 'meta'])->name('lookups.meta');
Route::get('search', [MetaController::class, 'search'])->middleware('throttle:60,1')->name('lookups.search');

Route::prefix('notifications')->name('notifications.')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
    Route::post('read-all', [NotificationController::class, 'markAllAsRead'])->name('read-all');
    Route::post('{notification}/read', [NotificationController::class, 'markAsRead'])->name('read');
});
