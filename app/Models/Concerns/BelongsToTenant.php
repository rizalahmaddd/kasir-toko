<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Data milik satu toko: query otomatis dibatasi ke tenant aktif dan tenant_id diisi saat dibuat.
 * Query lewat DB::table() tidak ikut terfilter, jadi WAJIB tambahkan where tenant_id sendiri.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * Diisi saat model dibuat, bukan lewat event creating, supaya tetap jalan di seeder yang
     * mematikan model event. Model dari database tetap memakai nilai aslinya.
     */
    public function initializeBelongsToTenant(): void
    {
        $tenantId = app(CurrentTenant::class)->id();

        if ($tenantId !== null && ! array_key_exists('tenant_id', $this->attributes)) {
            $this->attributes['tenant_id'] = $tenantId;
        }
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
