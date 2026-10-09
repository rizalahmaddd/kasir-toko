<?php

use App\Http\Controllers\PrintController;
use App\Livewire\Kitchen\KitchenBoard;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'can:kitchen.view'])->group(function () {
    Route::get('dapur', KitchenBoard::class)->name('kitchen.board');
});

// Kasir mencetak tiket dari layar kasir tanpa perlu akses Layar Dapur; izinnya diperiksa di controller.
Route::middleware(['auth', 'verified'])->get('dapur/tiket/{ticket}', [PrintController::class, 'kitchenTicket'])->name('print.kitchen-ticket');
