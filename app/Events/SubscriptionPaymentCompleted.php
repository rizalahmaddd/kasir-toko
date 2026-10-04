<?php

namespace App\Events;

use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Dipicu saat pembayaran invoice langganan via payment gateway berhasil diverifikasi.
 */
class SubscriptionPaymentCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public SubscriptionInvoice $invoice,
        public Tenant $tenant,
        public CarbonInterface|Carbon|null $newEndsAt = null
    ) {}
}
