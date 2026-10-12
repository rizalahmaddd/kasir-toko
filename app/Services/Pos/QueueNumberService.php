<?php

namespace App\Services\Pos;

use App\Models\Outlet;
use App\Models\Sale;
use App\Models\SelfOrder;

/**
 * Nomor antrean harian per outlet: satu deret untuk transaksi kasir dan pesanan QR, supaya dua pelanggan
 * tidak dipanggil dengan nomor yang sama.
 */
class QueueNumberService
{
    /**
     * WAJIB dipanggil di dalam transaksi database: baris outlet dikunci sampai nomor tersimpan.
     */
    public function next(int $outletId): int
    {
        Outlet::query()->withoutGlobalScopes()->whereKey($outletId)->lockForUpdate()->first();

        $sales = (int) Sale::query()->withoutGlobalScopes()->where('outlet_id', $outletId)->whereDate('sold_at', today())->max('queue_number');
        $selfOrders = (int) SelfOrder::query()->withoutGlobalScopes()->where('outlet_id', $outletId)->whereDate('created_at', today())->max('queue_number');

        return max($sales, $selfOrders) + 1;
    }
}
