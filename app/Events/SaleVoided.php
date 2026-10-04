<?php

namespace App\Events;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dipicu saat transaksi penjualan yang sudah selesai dibatalkan (void).
 */
class SaleVoided
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Sale $sale,
        public User $user,
        public string $reason
    ) {}
}
