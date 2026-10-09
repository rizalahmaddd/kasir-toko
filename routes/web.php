<?php

use App\Http\Controllers\AndroidDownloadController;
use App\Http\Controllers\Api\OpenApiDocumentController;
use App\Http\Controllers\BrandingLogoController;
use App\Livewire\Dashboard;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Aplikasi internal: tidak ada landing page, "/" langsung ke dashboard atau ke login.
Route::redirect('/', '/dashboard');

// Logo dari Pengaturan Perusahaan; publik karena dipakai juga di halaman login dan favicon.
Route::get('branding/logo', BrandingLogoController::class)->name('branding.logo');

Route::get('dashboard', Dashboard::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// Pemilik toko baru diarahkan ke sini oleh EnsureStoreOnboarded; dibuka lagi dari Pengaturan Perusahaan.
Volt::route('persiapan-toko', 'pages.onboarding')
    ->middleware(['auth', 'verified'])
    ->name('onboarding');

// Rendered by Scalar at /docs/api. Lives on the web stack because the docs page fetches it with the
// superadmin's session cookie; mobile developers can also export it with `php artisan api:docs`.
Route::get('api/openapi.json', OpenApiDocumentController::class)
    ->middleware(['auth', 'can:view-api-docs'])
    ->name('api.openapi');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');
// Halaman Legal & Kepatuhan Google Play Store (Publik, tanpa auth)
Route::view('privacy-policy', 'legal.privacy')->name('legal.privacy');
Route::view('kebijakan-privasi', 'legal.privacy');
Route::view('terms-of-service', 'legal.terms')->name('legal.terms');
Route::view('ketentuan-layanan', 'legal.terms');
Route::view('delete-account', 'legal.delete_account')->name('legal.delete-account');
Route::view('hapus-akun', 'legal.delete_account');

Route::get('unduh-aplikasi', [AndroidDownloadController::class, 'show'])->name('app.download');
Route::redirect('download', '/unduh-aplikasi');
Route::get('unduh-aplikasi/android/{abi}', [AndroidDownloadController::class, 'download'])
    ->middleware('throttle:30,1')
    ->name('app.download.apk');

require __DIR__.'/auth.php';
require __DIR__.'/pos.php';
require __DIR__.'/master-data.php';
require __DIR__.'/pharmacy.php';
require __DIR__.'/kitchen.php';
require __DIR__.'/orders.php';
require __DIR__.'/reports.php';
require __DIR__.'/settings.php';
require __DIR__.'/platform.php';
