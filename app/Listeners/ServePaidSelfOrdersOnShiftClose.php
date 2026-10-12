<?php

namespace App\Listeners;

use App\Events\CashShiftClosed;
use App\Services\Pos\SelfOrderService;

/**
 * Pesanan QR yang sudah lunas dan siap, tapi lupa ditandai diantar, tidak dibiarkan menggantung sampai besok.
 */
class ServePaidSelfOrdersOnShiftClose
{
    public function __construct(private SelfOrderService $selfOrders) {}

    public function handle(CashShiftClosed $event): void
    {
        $this->selfOrders->serveReadyPaid((int) $event->shift->outlet_id);
    }
}
