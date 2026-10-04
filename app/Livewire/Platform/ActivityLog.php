<?php

namespace App\Livewire\Platform;

use App\Livewire\Reports\ActivityLogReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Spatie\Activitylog\Models\Activity;

/**
 * Jejak audit pengelola layanan: login admin platform, perubahan pengaturan & paket, langganan
 * toko, reset password, dan ekspor. Log milik toko yang tidak disentuh admin platform tidak
 * ikut tampil, karena tanpa tenant aktif query Activity tidak terfilter sama sekali.
 */
#[Layout('layouts.app', ['heading' => 'Log Aktivitas Platform'])]
#[Title('Log Aktivitas Platform')]
class ActivityLog extends ActivityLogReport
{
    protected function authorizeView(): void
    {
        abort_unless(auth()->user()->can('manage-platform'), 403);
    }

    protected function baseQuery(): Builder
    {
        $user = (new User)->getMorphClass();

        return Activity::query()->where(fn (Builder $query) => $query
            ->whereNull('tenant_id')
            ->orWhere(fn (Builder $causer) => $causer
                ->where('causer_type', $user)
                ->whereIn('causer_id', User::query()->withoutGlobalScopes()->whereNull('tenant_id')->select('id'))));
    }
}
