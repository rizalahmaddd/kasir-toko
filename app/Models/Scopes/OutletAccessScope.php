<?php

namespace App\Models\Scopes;

use App\Support\CurrentOutlet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Membatasi user yang ditugaskan ke outlet tertentu (mis. kasir cabang) ke data outlet itu saja.
 * Pemilik dan admin tidak difilter di sini; layar daftar dan laporan menyaring outlet secara eksplisit.
 */
class OutletAccessScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $restricted = app(CurrentOutlet::class)->restrictedTo();

        if ($restricted !== null) {
            $builder->whereIn($model->qualifyColumn('outlet_id'), $restricted);
        }
    }
}
