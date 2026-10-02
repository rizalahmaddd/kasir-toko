<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->middleware('throttle:10,1')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:5,60')->name('register');
    Route::post('otp/send', [AuthController::class, 'sendOtp'])->name('otp.send');
    Route::post('otp/verify', [AuthController::class, 'verifyOtp'])->name('otp.verify');
});
