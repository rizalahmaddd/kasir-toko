<?php

namespace App\Events;

use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Dipicu saat Admin Platform memberikan tambahan masa aktif atau bonus paket Pro ke tenant.
 */
class SubscriptionBonusGranted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public CarbonInterface|Carbon $accessEndsAt,
        public ?string $note = null,
        public ?int $addedDays = null
    ) {}
}
