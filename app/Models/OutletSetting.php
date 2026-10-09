<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\OutletSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penimpaan setting kasir untuk satu outlet (lihat App\Support\OutletSettings untuk kunci yang boleh).
 */
class OutletSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = ['outlet_id', 'key', 'value'];

    protected static function booted(): void
    {
        static::saved(fn (OutletSetting $setting) => OutletSettings::forget($setting->outlet_id));
        static::deleted(fn (OutletSetting $setting) => OutletSettings::forget($setting->outlet_id));
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
