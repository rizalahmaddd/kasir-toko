<?php

use App\Http\Controllers\Api\V1\OnboardingController;
use Illuminate\Support\Facades\Route;

Route::prefix('onboarding')->name('onboarding.')->group(function () {
    Route::get('presets', [OnboardingController::class, 'presets'])->name('presets');
    Route::post('apply', [OnboardingController::class, 'apply'])->name('apply');
    Route::post('skip', [OnboardingController::class, 'skip'])->name('skip');
});
