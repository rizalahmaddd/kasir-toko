<?php

use App\Http\Controllers\PrintController;
use App\Livewire\Inventory\ExpiryReport;
use App\Livewire\Inventory\SerialNumbers;
use App\Livewire\Inventory\StockCounts;
use App\Livewire\Inventory\StockCountShow;
use App\Livewire\Inventory\StockIndex;
use App\Livewire\Inventory\StockTransfers;
use App\Livewire\MasterData\Categories;
use App\Livewire\MasterData\Customers;
use App\Livewire\MasterData\CustomerShow;
use App\Livewire\MasterData\ModifierGroups;
use App\Livewire\MasterData\Products;
use Illuminate\Support\Facades\Route;

// Daftar & detail butuh izin lihat (view-master-data); tambah/ubah/hapus dibatasi di dalam
// komponen lewat WithCrudActions::canManage().
Route::middleware(['auth', 'verified', 'can:view-master-data'])->prefix('master-data')->name('master-data.')->group(function () {
    Route::get('produk', Products::class)->name('products');
    Route::get('kategori', Categories::class)->name('categories');
    Route::get('pilihan-tambahan', ModifierGroups::class)->name('modifiers');
    Route::get('pelanggan', Customers::class)->name('customers');
    Route::get('pelanggan/{customer}', CustomerShow::class)->name('customers.show');
    Route::get('pelanggan/{customer}/cetak', [PrintController::class, 'customer'])->name('customers.print');
});

Route::middleware(['auth', 'verified', 'can:view-master-data'])->prefix('stok')->name('inventory.')->group(function () {
    Route::get('/', StockIndex::class)->name('stock');
    Route::get('transfer', StockTransfers::class)->middleware('can:inventory.transfer')->name('transfers');
    Route::get('kedaluwarsa', ExpiryReport::class)->middleware('pro')->name('expiry');
    Route::get('nomor-seri', SerialNumbers::class)->name('serials');
    Route::middleware('can:inventory.opname.count')->group(function () {
        Route::get('opname', StockCounts::class)->name('opname');
        Route::get('opname/{stockCount}', StockCountShow::class)->name('opname.show');
        Route::get('opname/{stockCount}/lembar', [PrintController::class, 'stockCountSheet'])->name('opname.sheet');
        Route::get('opname/{stockCount}/cetak', [PrintController::class, 'stockCountReport'])->name('opname.print');
    });
});
