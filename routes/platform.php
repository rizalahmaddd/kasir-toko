<?php

use App\Http\Controllers\BackupDownloadController;
use App\Livewire\Platform\Admins;
use App\Livewire\Platform\Dashboard;
use App\Livewire\Platform\ServiceSettings;
use App\Livewire\Platform\Tenants;
use App\Livewire\Platform\TenantShow;
use App\Livewire\Settings\Backups;
use Illuminate\Support\Facades\Route;

// Panel pengelola layanan SaaS; hanya untuk akun admin platform (tanpa toko).
Route::middleware(['auth', 'verified', 'can:manage-platform'])->prefix('platform')->name('platform.')->group(function () {
    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('toko', Tenants::class)->name('tenants');
    Route::get('toko/{tenant}', TenantShow::class)->name('tenants.show');
    Route::get('admin', Admins::class)->name('admins');
    Route::get('pengaturan', ServiceSettings::class)->name('settings');
    Route::get('backup', Backups::class)->name('backups');
    Route::get('backup/{file}/unduh', BackupDownloadController::class)->name('backups.download');
});
