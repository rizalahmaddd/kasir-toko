<?php

use App\Http\Controllers\BrandingLogoController;
use App\Livewire\Dashboard;
use Illuminate\Support\Facades\Route;

// Aplikasi internal: tidak ada landing page, "/" langsung ke dashboard atau ke login.
Route::redirect('/', '/dashboard');

// Logo dari Pengaturan Perusahaan; publik karena dipakai juga di halaman login dan favicon.
Route::get('branding/logo', BrandingLogoController::class)->name('branding.logo');

Route::get('dashboard', Dashboard::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

require __DIR__.'/auth.php';
require __DIR__.'/pos.php';
require __DIR__.'/master-data.php';
require __DIR__.'/reports.php';
require __DIR__.'/settings.php';
