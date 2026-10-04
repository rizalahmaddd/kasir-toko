<?php

namespace App\Events;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dipicu saat toko baru dan akun pemiliknya berhasil dibuat (terdaftar).
 */
class TenantRegistered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public User $owner
    ) {}
}
