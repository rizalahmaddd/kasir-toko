<?php

namespace App\Services;

use App\Events\SubscriptionBonusGranted;
use App\Models\Outlet;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\TenantSubscriptionLog;
use App\Notifications\OutletsLockedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya jalan admin platform mengubah langganan toko, supaya setiap perubahan paket,
 * masa aktif, dan status tercatat di riwayat langganan.
 */
class TenantSubscriptionManager
{
    /**
     * @param  array{name: string, plan: string, status: string, access_ends_at: ?Carbon}  $data
     */
    public function update(Tenant $tenant, array $data, ?int $amount = null, ?string $note = null): void
    {
        DB::transaction(function () use ($tenant, $data, $amount, $note) {
            $before = $this->snapshot($tenant);
            $endsAt = $data['access_ends_at']?->copy()->endOfDay();

            $tenant->update([
                'name' => $data['name'],
                'plan' => $data['plan'],
                'status' => $data['status'],
                ...($data['plan'] === Tenant::PLAN_TRIAL ? ['trial_ends_at' => $endsAt] : ['subscription_ends_at' => $endsAt]),
            ]);

            $after = $this->snapshot($tenant);

            if ($before !== $after || $amount !== null) {
                $this->log($tenant, TenantSubscriptionLog::ACTION_UPDATE, $before, $amount, $note);

                $planOrExpiryChanged = ($before['ends_at'] !== $after['ends_at'] || $before['plan'] !== $after['plan']);

                if ($endsAt && $planOrExpiryChanged && ! str_contains((string) $note, 'SumoPod')) {
                    SubscriptionBonusGranted::dispatch($tenant, $endsAt, $note);
                }
            }

            if ($before['plan'] !== $after['plan']) {
                $this->notifyLockedOutlets($tenant);
            }
        });
    }

    /**
     * Penimpaan batas outlet khusus satu toko (kosong = ikut paket). Pemilik diberi tahu bila
     * batas baru menyisakan outlet yang terkunci.
     */
    public function setOutletOverride(Tenant $tenant, ?int $limit): void
    {
        if ($tenant->max_outlets_override === $limit) {
            return;
        }

        $tenant->update(['max_outlets_override' => $limit]);

        activity('platform')->performedOn($tenant)->event('update')
            ->log("Batas outlet {$tenant->name} ".($limit === null ? 'dikembalikan mengikuti paket.' : "diatur khusus menjadi {$limit}."));

        $this->notifyLockedOutlets($tenant);
    }

    /**
     * Outlet berlebih tidak dihapus, hanya terkunci; pemilik diberi tahu daftarnya.
     */
    private function notifyLockedOutlets(Tenant $tenant): void
    {
        $max = $tenant->maxOutlets();
        $locked = Outlet::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)->active()->byPriority()->skip($max)->take(1000)->pluck('name')->all();

        if ($locked !== [] && ($owner = $tenant->owner())) {
            $owner->notify(new OutletsLockedNotification($tenant, $locked, $max));
        }
    }

    /**
     * Perpanjangan dihitung dari akhir masa aktif yang masih berjalan, atau dari hari ini kalau sudah lewat.
     */
    public function extend(Tenant $tenant, int $days, ?int $amount = null, ?string $note = null): void
    {
        DB::transaction(function () use ($tenant, $days, $amount, $note) {
            $before = $this->snapshot($tenant);
            $column = $tenant->isOnTrial() ? 'trial_ends_at' : 'subscription_ends_at';
            $from = $tenant->accessEndsAt()?->isFuture() ? $tenant->accessEndsAt() : now();
            $newEndsAt = $from->copy()->addDays($days)->endOfDay();

            $tenant->update([$column => $newEndsAt]);

            $this->log($tenant, TenantSubscriptionLog::ACTION_EXTEND, $before, $amount, $note);

            SubscriptionBonusGranted::dispatch($tenant, $newEndsAt, $note, $days);
        });
    }

    public function setStatus(Tenant $tenant, string $status, ?string $note = null): void
    {
        if ($tenant->status === $status) {
            return;
        }

        DB::transaction(function () use ($tenant, $status, $note) {
            $before = $this->snapshot($tenant);

            $tenant->update(['status' => $status]);

            $this->log($tenant, TenantSubscriptionLog::ACTION_STATUS, $before, null, $note);
        });
    }

    /**
     * @return array{plan: string, status: string, ends_at: ?string}
     */
    private function snapshot(Tenant $tenant): array
    {
        return [
            'plan' => $tenant->plan,
            'status' => $tenant->status,
            'ends_at' => $tenant->accessEndsAt()?->toDateTimeString(),
        ];
    }

    /**
     * @param  array{plan: string, status: string, ends_at: ?string}  $before
     */
    private function log(Tenant $tenant, string $action, array $before, ?int $amount, ?string $note): void
    {
        $log = TenantSubscriptionLog::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => Auth::id(),
            'action' => $action,
            'from_plan' => $before['plan'],
            'to_plan' => $tenant->plan,
            'from_status' => $before['status'],
            'to_status' => $tenant->status,
            'from_ends_at' => $before['ends_at'],
            'to_ends_at' => $tenant->accessEndsAt(),
            'amount' => $amount,
            'note' => filled($note) ? trim($note) : null,
        ]);

        activity('platform')->performedOn($tenant)->event($action)
            ->withProperties(['old' => $before, 'attributes' => $this->snapshot($tenant), 'amount' => $amount])
            ->log("Langganan {$tenant->name}: {$log->actionLabel()}.");
    }
}
