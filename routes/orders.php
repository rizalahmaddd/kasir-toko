<?php

use App\Http\Controllers\PrintController;
use App\Livewire\Orders\CustomerOrders;
use App\Livewire\Orders\CustomerOrderShow;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'can:orders.manage'])->prefix('pesanan')->name('orders.')->group(function () {
    Route::get('/', CustomerOrders::class)->name('index');
    Route::get('{order}', CustomerOrderShow::class)->name('show');
    Route::get('{order}/cetak', [PrintController::class, 'customerOrder'])->name('print');
});

Route::middleware(['auth', 'verified', 'can:pos.sell'])->get('surat-jalan/{note}', [PrintController::class, 'deliveryNote'])->name('delivery-notes.print');
