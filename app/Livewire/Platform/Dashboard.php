<?php

namespace App\Livewire\Platform;

use App\Enums\StoreType;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\TenantSubscriptionLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Ringkasan layanan untuk admin platform: kondisi toko pelanggan, masa aktif yang hampir habis,
 * dan pendapatan langganan yang dicatat manual.
 */
#[Layout('layouts.app', ['heading' => 'Dashboard Platform'])]
#[Title('Dashboard Platform')]
class Dashboard extends Component
{
    /**
     * @return array{total: int, active: int, trial: int, expired: int, suspended: int, new_30d: int, pending_onboarding: int}
     */
    public function tenantCounts(): array
    {
        $tenants = Tenant::query()->get(['id', 'status', 'plan', 'trial_ends_at', 'subscription_ends_at', 'onboarded_at', 'created_at']);

        return [
            'total' => $tenants->count(),
            'active' => $tenants->filter(fn (Tenant $tenant) => $tenant->blockedReason() === null)->count(),
            'trial' => $tenants->filter(fn (Tenant $tenant) => $tenant->isOnTrial() && $tenant->blockedReason() === null)->count(),
            'expired' => $tenants->filter(fn (Tenant $tenant) => $tenant->isActive() && $tenant->hasExpired())->count(),
            'suspended' => $tenants->reject(fn (Tenant $tenant) => $tenant->isActive())->count(),
            'new_30d' => $tenants->filter(fn (Tenant $tenant) => $tenant->created_at?->gte(now()->subDays(30)))->count(),
            'pending_onboarding' => $tenants->reject(fn (Tenant $tenant) => $tenant->isOnboarded())->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function planCounts(): array
    {
        $counts = Tenant::query()->select('plan', DB::raw('count(*) as total'))->groupBy('plan')->pluck('total', 'plan');

        return collect(config('saas.plans'))
            ->mapWithKeys(fn (array $plan, string $key) => [$plan['label'] => (int) ($counts[$key] ?? 0)])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    public function storeTypeCounts(): array
    {
        return Tenant::query()
            ->whereNotNull('store_type')
            ->select('store_type', DB::raw('count(*) as total'))
            ->groupBy('store_type')
            ->orderByDesc('total')
            ->pluck('total', 'store_type')
            ->mapWithKeys(fn ($total, string $type) => [StoreType::tryFrom($type)?->label() ?? $type => (int) $total])
            ->all();
    }

    /**
     * Toko yang masih aktif tetapi masa aktifnya habis dalam 7 hari: kandidat untuk dihubungi.
     *
     * @return Collection<int, Tenant>
     */
    public function endingSoon(): Collection
    {
        $until = now()->addDays(7);

        return Tenant::query()
            ->where('status', Tenant::STATUS_ACTIVE)
            ->where(fn ($query) => $query
                ->where(fn ($trial) => $trial->where('plan', Tenant::PLAN_TRIAL)->whereBetween('trial_ends_at', [now(), $until]))
                ->orWhere(fn ($paid) => $paid->where('plan', '!=', Tenant::PLAN_TRIAL)->whereBetween('subscription_ends_at', [now(), $until])))
            ->get()
            ->sortBy(fn (Tenant $tenant) => $tenant->accessEndsAt())
            ->values();
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function mostActive(): Collection
    {
        $activity = Sale::query()->withoutGlobalScopes()
            ->completed()
            ->where('sold_at', '>=', now()->subDays(30))
            ->select('tenant_id', DB::raw('count(*) as sales_count'), DB::raw('sum(total) as revenue'))
            ->groupBy('tenant_id')
            ->orderByDesc('sales_count')
            ->limit(5)
            ->get()
            ->keyBy('tenant_id');

        return Tenant::query()
            ->whereIn('id', $activity->keys())
            ->get()
            ->each(function (Tenant $tenant) use ($activity) {
                $tenant->setAttribute('sales_count', (int) $activity[$tenant->id]->sales_count);
                $tenant->setAttribute('revenue', (int) $activity[$tenant->id]->revenue);
            })
            ->sortByDesc('sales_count')
            ->values();
    }

    /**
     * @return array{this_month: int, last_30d: int}
     */
    public function subscriptionRevenue(): array
    {
        return [
            'this_month' => (int) TenantSubscriptionLog::query()->where('created_at', '>=', now()->startOfMonth())->sum('amount'),
            'last_30d' => (int) TenantSubscriptionLog::query()->where('created_at', '>=', now()->subDays(30))->sum('amount'),
        ];
    }

    /**
     * @return Collection<int, TenantSubscriptionLog>
     */
    public function recentPayments(): Collection
    {
        return TenantSubscriptionLog::query()
            ->with('tenant')
            ->whereNotNull('amount')
            ->latest('id')
            ->limit(5)
            ->get();
    }

    public function render()
    {
        return view('livewire.platform.dashboard', [
            'counts' => $this->tenantCounts(),
            'plans' => $this->planCounts(),
            'storeTypes' => $this->storeTypeCounts(),
            'endingSoon' => $this->endingSoon(),
            'mostActive' => $this->mostActive(),
            'revenue' => $this->subscriptionRevenue(),
            'recentPayments' => $this->recentPayments(),
        ]);
    }
}
