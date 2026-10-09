<?php

use App\Http\Controllers\Api\V1\Pharmacy\PrescriptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('pharmacy')->name('pharmacy.')->middleware('feature:business.prescription')->group(function () {
    Route::get('prescriptions', [PrescriptionController::class, 'index'])->name('prescriptions.index');
    Route::post('prescriptions', [PrescriptionController::class, 'store'])->name('prescriptions.store');
    Route::get('prescriptions/{prescription}', [PrescriptionController::class, 'show'])->name('prescriptions.show');
    Route::put('prescriptions/{prescription}', [PrescriptionController::class, 'update'])->name('prescriptions.update');
    Route::post('prescriptions/{prescription}/verify', [PrescriptionController::class, 'verify'])->name('prescriptions.verify');
    Route::post('prescriptions/{prescription}/cancel', [PrescriptionController::class, 'cancel'])->name('prescriptions.cancel');
    Route::post('prescriptions/{prescription}/image', [PrescriptionController::class, 'uploadImage'])->name('prescriptions.image.store');
    Route::get('prescriptions/{prescription}/image', [PrescriptionController::class, 'image'])->name('prescriptions.image.show');
});
