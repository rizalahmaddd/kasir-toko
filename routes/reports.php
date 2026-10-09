<?php

use App\Livewire\Reports\ActivityLogReport;
use App\Livewire\Reports\SalesReport;
use App\Livewire\Reports\StockVarianceReport;
use Illuminate\Support\Facades\Route;

// Menu "Laporan". Setiap komponen menggate aksesnya sendiri di mount().
Route::middleware(['auth', 'verified'])->prefix('laporan')->name('reports.')->group(function () {
    Route::get('penjualan', SalesReport::class)->name('sales');
    Route::get('selisih-stok', StockVarianceReport::class)->name('stock-variance');
    Route::get('aktivitas', ActivityLogReport::class)->name('activity-log');
});
