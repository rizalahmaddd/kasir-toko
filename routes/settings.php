<?php

use App\Http\Controllers\TenantDataExportController;
use App\Livewire\Settings\CompanyProfile;
use App\Livewire\Settings\CustomerDisplaySettingsPage;
use App\Livewire\Settings\DataExport;
use App\Livewire\Settings\FeatureToggles;
use App\Livewire\Settings\PosSettingsPage;
use App\Livewire\Settings\RolesAndPermissions;
use App\Livewire\Settings\SubscriptionPage;
use Illuminate\Support\Facades\Route;

// Pengaturan Perusahaan, Peran / Hak Akses, dan sakelar fitur milik toko
Route::middleware(['auth', 'verified'])->prefix('pengaturan')->name('settings.')->group(function () {
    Route::get('perusahaan', CompanyProfile::class)->name('company-profile');
    Route::get('kasir', PosSettingsPage::class)->name('pos');
    Route::get('layar-pelanggan', CustomerDisplaySettingsPage::class)->middleware('pro')->name('customer-display');
    Route::get('peran-izin', RolesAndPermissions::class)->name('roles-and-permissions');
    Route::get('fitur', FeatureToggles::class)->name('features');
    Route::get('langganan', SubscriptionPage::class)->name('subscription');
    Route::get('ekspor-data', DataExport::class)->middleware('pro')->name('data-export');
    Route::get('ekspor-data/unduh', TenantDataExportController::class)->middleware(['pro', 'throttle:5,10'])->name('data-export.download');
});
