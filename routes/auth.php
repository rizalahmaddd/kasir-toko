<?php

use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Pendaftaran membuat toko baru beserta pemiliknya; akun pegawai dibuat dari Pengaturan > Peran & Perizinan.
Route::middleware('guest')->group(function () {
    Route::get('auth/google', [SocialAuthController::class, 'redirectGoogle'])
        ->name('auth.google');
    Route::get('auth/google/callback', [SocialAuthController::class, 'callbackGoogle'])
        ->name('auth.google.callback');

    Volt::route('login', 'pages.auth.login')
        ->name('login');

    Volt::route('daftar', 'pages.auth.register')
        ->name('register');

    Volt::route('forgot-password', 'pages.auth.forgot-password')
        ->name('password.request');

    Volt::route('reset-password/{token}', 'pages.auth.reset-password')
        ->name('password.reset');
});

Route::middleware('auth')->group(function () {
    Volt::route('langganan', 'pages.subscription-inactive')
        ->name('subscription.inactive');

    Volt::route('verify-email', 'pages.auth.verify-email')
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Volt::route('confirm-password', 'pages.auth.confirm-password')
        ->name('password.confirm');

    Route::delete('auth/account', [SocialAuthController::class, 'deleteAccount'])
        ->name('auth.account.delete');
});
