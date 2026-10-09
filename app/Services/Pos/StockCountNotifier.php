<?php

namespace App\Services\Pos;

use App\Models\Outlet;
use App\Models\Setting;
use App\Models\StockCount;
use App\Models\User;
use App\Notifications\StockCountPostedNotification;
use App\Notifications\StockCountSubmittedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Kabar opname untuk pengelola opname yang memegang outlet dokumen itu.
 */
class StockCountNotifier
{
    public function submitted(StockCount $count, User $counter): void
    {
        $recipients = $this->managers($count)->reject(fn (User $user) => $user->is($counter));

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new StockCountSubmittedNotification($count, $counter->name));
        }
    }

    /**
     * Kurang di atas ambang "kabari pemilik" ikut dikirim ke yang menyelesaikan, supaya tercatat di lonceng semua pengelola.
     */
    public function posted(StockCount $count): void
    {
        $threshold = (int) Setting::get(StockCountService::ALERT_ABOVE_KEY, '0');
        $alert = $threshold > 0 && (int) ($count->summary['shortage_value'] ?? 0) > $threshold;
        $recipients = $alert ? $this->managers($count) : $this->managers($count)->reject(fn (User $user) => $user->id === $count->posted_by);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new StockCountPostedNotification($count, $alert));
        }
    }

    /**
     * @return Collection<int, User>
     */
    public function managers(StockCount $count): Collection
    {
        $multiOutlet = Outlet::query()->withoutGlobalScopes()->where('tenant_id', $count->tenant_id)->count() > 1;

        return User::query()
            ->where('tenant_id', $count->tenant_id)
            ->when($multiOutlet, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('all_outlets', true)
                ->orWhereHas('outlets', fn (Builder $query) => $query->whereKey($count->outlet_id))
                ->orWhereHas('roles', fn (Builder $query) => $query->where('name', 'superadmin'))))
            ->where(fn (Builder $query) => $query
                ->whereHas('roles.permissions', fn (Builder $query) => $query->where('name', 'inventory.opname.manage'))
                ->orWhereHas('permissions', fn (Builder $query) => $query->where('name', 'inventory.opname.manage'))
                ->orWhereHas('roles', fn (Builder $query) => $query->where('name', 'superadmin')))
            ->get();
    }
}
