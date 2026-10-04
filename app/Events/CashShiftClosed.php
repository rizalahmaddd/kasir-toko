<?php

namespace App\Events;

use App\Models\CashShift;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dipicu saat kasir/staf menutup shift kasir di POS.
 */
class CashShiftClosed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public CashShift $shift,
        public User $closer
    ) {}
}
