<?php

namespace App\Events;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dipicu saat kasbon baru dibuat atau pelunasan kasbon diterima.
 */
class CustomerReceivableRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Sale $sale,
        public int $amount,
        public string $type, // 'created' | 'collected'
        public ?User $actor = null
    ) {}
}
