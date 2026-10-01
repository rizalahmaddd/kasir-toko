<?php

namespace App\Models\Concerns;

use App\Support\Audit\AuditTrail;
use Illuminate\Support\Arr;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Mencatat setiap create/update/delete/restore model ke activity_log dengan snapshot JSON:
 * created = seluruh kolom, updated = hanya kolom yang berubah (old vs attributes), deleted = seluruh kolom terakhir.
 * Perubahan lewat query builder (Model::where()->update(), ->delete(), DB::table) TIDAK tercatat, jadi hindari di kode aplikasi.
 */
trait Auditable
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(AuditTrail::LOG_NAME)
            ->logAll()
            ->logExcept(array_values(array_unique([...$this->getHidden(), ...AuditTrail::EXCLUDED_ATTRIBUTES])))
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => AuditTrail::describe($this, $event));
    }

    /**
     * Kolom yang tidak ikut di-load (mis. default dari DB) terbaca null di sisi "old" dan tampil
     * seolah berubah; yang dicatat hanya kolom yang benar-benar di-update Eloquent.
     */
    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        if ($eventName !== 'updated') {
            return;
        }

        $changed = array_keys($this->getChanges());
        $changes = $activity->attribute_changes?->toArray() ?? [];

        $activity->attribute_changes = collect([
            'attributes' => Arr::only($changes['attributes'] ?? [], $changed),
            'old' => Arr::only($changes['old'] ?? [], $changed),
        ]);
    }
}
