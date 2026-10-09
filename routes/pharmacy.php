<?php

use App\Http\Controllers\PrescriptionController;
use App\Livewire\Pharmacy\PrescriptionReport;
use App\Livewire\Pharmacy\Prescriptions;
use App\Livewire\Pharmacy\PrescriptionShow;
use Illuminate\Support\Facades\Route;

// Data pasien & foto resep hanya untuk pengguna berizin pharmacy.prescription.view (UU PDP).
Route::middleware(['auth', 'verified', 'can:pharmacy.prescription.view'])->prefix('resep')->name('pharmacy.')->group(function () {
    Route::get('/', Prescriptions::class)->name('prescriptions');
    Route::get('laporan-obat-keras', PrescriptionReport::class)->middleware('pro')->name('report');
    Route::get('{prescription}', PrescriptionShow::class)->name('prescriptions.show');
    Route::get('{prescription}/foto', [PrescriptionController::class, 'image'])->name('prescriptions.image');
    Route::get('{prescription}/salinan', [PrescriptionController::class, 'copy'])->middleware('pro')->name('prescriptions.copy');
    Route::get('{prescription}/etiket', [PrescriptionController::class, 'labels'])->middleware('pro')->name('prescriptions.labels');
});
