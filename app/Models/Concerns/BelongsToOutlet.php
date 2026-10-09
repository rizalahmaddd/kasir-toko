<?php

namespace App\Models\Concerns;

use App\Models\Outlet;
use App\Models\Scopes\OutletAccessScope;
use App\Support\CurrentOutlet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Data yang dicatat per outlet. outlet_id diisi dari outlet aktif kecuali sudah ditentukan (mis. dari
 * shift). Query lewat DB::table() tidak ikut OutletAccessScope, jadi laporan WAJIB menyaring outlet_id sendiri.
 */
trait BelongsToOutlet
{
    /**
     * Anak dari dokumen yang sudah dibatasi (pembayaran, kas laci) menimpa ini dengan false:
     * pembayaran kasbon bisa diterima di outlet lain daripada transaksinya, dan tidak boleh
     * hilang dari rincian transaksi.
     */
    protected static function limitsToAccessibleOutlets(): bool
    {
        return true;
    }

    public static function bootBelongsToOutlet(): void
    {
        if (static::limitsToAccessibleOutlets()) {
            static::addGlobalScope(new OutletAccessScope);
        }
    }

    /**
     * Diisi saat model dibuat, bukan lewat event creating, supaya tetap jalan di seeder yang mematikan model event.
     */
    public function initializeBelongsToOutlet(): void
    {
        $this->mergeCasts(['outlet_id' => 'integer']);

        if (! array_key_exists('outlet_id', $this->attributes)) {
            $outletId = app(CurrentOutlet::class)->idOrPrimary();

            if ($outletId !== null) {
                $this->attributes['outlet_id'] = $outletId;
            }
        }
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * Null berarti semua outlet.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForOutlet(Builder $query, ?int $outletId): void
    {
        if ($outletId !== null) {
            $query->where($this->qualifyColumn('outlet_id'), $outletId);
        }
    }
}
