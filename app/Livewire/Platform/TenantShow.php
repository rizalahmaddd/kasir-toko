<?php

namespace App\Livewire\Platform;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\TenantSubscriptionLog;
use App\Models\User;
use App\Services\TenantSubscriptionManager;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Detail satu toko untuk admin platform. Halaman ini berjalan tanpa tenant aktif, jadi setiap
 * query data toko WAJIB difilter tenant_id secara eksplisit.
 */
class TenantShow extends Component
{
    public Tenant $tenant;

    public string $plan = '';

    public string $access_ends_at = '';

    public string $extendDays = '30';

    public string $amount = '';

    public string $note = '';

    public string $newPassword = '';

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function openExtend(): void
    {
        $this->resetForm();
        $this->dispatch('open-modal', 'extend-tenant');
    }

    public function openChangePlan(): void
    {
        $this->resetForm();
        $this->plan = $this->tenant->plan;
        $this->access_ends_at = $this->tenant->accessEndsAt()?->toDateString() ?? '';
        $this->dispatch('open-modal', 'change-plan');
    }

    public function openResetPassword(): void
    {
        $this->resetForm();
        $this->dispatch('open-modal', 'reset-owner-password');
    }

    public function extend(TenantSubscriptionManager $subscriptions): void
    {
        $this->authorizeManage();

        $this->amount = preg_replace('/\D/', '', $this->amount) ?? '';
        $validated = $this->validate([
            'extendDays' => ['required', 'integer', Rule::in([7, 30, 90, 180, 365])],
            ...$this->paymentRules(),
        ]);

        $subscriptions->extend($this->tenant, (int) $validated['extendDays'], $this->amountValue(), $validated['note']);

        $this->dispatch('close-modal', 'extend-tenant');
        $this->notify("Masa aktif diperpanjang {$validated['extendDays']} hari.");
    }

    public function changePlan(TenantSubscriptionManager $subscriptions): void
    {
        $this->authorizeManage();

        $this->amount = preg_replace('/\D/', '', $this->amount) ?? '';
        $validated = $this->validate([
            'plan' => ['required', Rule::in(array_keys(config('saas.plans')))],
            'access_ends_at' => ['nullable', 'date'],
            ...$this->paymentRules(),
        ]);

        $subscriptions->update($this->tenant, [
            'name' => $this->tenant->name,
            'plan' => $validated['plan'],
            'status' => $this->tenant->status,
            'access_ends_at' => $validated['access_ends_at'] ? Carbon::parse($validated['access_ends_at']) : null,
        ], $this->amountValue(), $validated['note']);

        $this->dispatch('close-modal', 'change-plan');
        $this->notify('Paket toko diperbarui.');
    }

    public function toggleStatus(TenantSubscriptionManager $subscriptions): void
    {
        $this->authorizeManage();

        $suspending = $this->tenant->isActive();
        $subscriptions->setStatus($this->tenant, $suspending ? Tenant::STATUS_SUSPENDED : Tenant::STATUS_ACTIVE);

        $this->dispatch('close-modal', 'toggle-status');
        $this->notify($suspending ? 'Toko dinonaktifkan. Penggunanya tidak bisa masuk.' : 'Toko diaktifkan kembali.');
    }

    public function resetOwnerPassword(): void
    {
        $this->authorizeManage();

        $owner = $this->tenant->owner();
        abort_if($owner === null, 404);

        $this->validate(['newPassword' => ['required', 'string', Password::defaults()]]);

        $owner->forceFill(['password' => Hash::make($this->newPassword)])->save();
        $owner->tokens()->delete();

        // Dicatat di tenant toko itu supaya pemiliknya juga melihat jejaknya di Log Aktivitas.
        app(CurrentTenant::class)->run($this->tenant, fn () => activity()->performedOn($owner)->event('password-reset')->log('Password pemilik direset oleh admin platform'));

        $this->dispatch('close-modal', 'reset-owner-password');
        $this->resetForm();
        $this->notify("Password {$owner->name} direset. Sampaikan password baru ke pemilik toko.");
    }

    /**
     * @return array{users: int, products: int, sales: int, revenue: int, sales_30d: int, revenue_30d: int, last_sale_at: ?Carbon}
     */
    #[Computed]
    public function usage(): array
    {
        $sales = Sale::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->completed();
        $recent = (clone $sales)->where('sold_at', '>=', now()->subDays(30));
        $lastSale = (clone $sales)->max('sold_at');

        return [
            'users' => User::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count(),
            'products' => Product::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count(),
            'sales' => (clone $sales)->count(),
            'revenue' => (int) (clone $sales)->sum('total'),
            'sales_30d' => (clone $recent)->count(),
            'revenue_30d' => (int) (clone $recent)->sum('total'),
            'last_sale_at' => $lastSale ? Carbon::parse($lastSale) : null,
        ];
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        $roles = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.tenant_id', $this->tenant->id)
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->get(['model_has_roles.model_id', 'roles.name'])
            ->groupBy('model_id');

        return User::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->orderBy('id')
            ->get()
            ->each(fn (User $user) => $user->setAttribute('role_names', $roles->get($user->id)?->pluck('name')->join(', ') ?? '-'));
    }

    #[Computed]
    public function owner(): ?User
    {
        return $this->tenant->owner();
    }

    /**
     * @return Collection<int, TenantSubscriptionLog>
     */
    #[Computed]
    public function subscriptionLogs(): Collection
    {
        return $this->tenant->subscriptionLogs()->with('user')->latest('id')->limit(20)->get();
    }

    public function canManage(): bool
    {
        return auth()->user()->can('manage-platform');
    }

    public function render()
    {
        return view('livewire.platform.tenant-show', [
            'plans' => collect(config('saas.plans'))->map(fn (array $plan) => $plan['label'])->all(),
        ])
            ->layout('layouts.app', ['heading' => $this->tenant->name])
            ->title($this->tenant->name);
    }

    private function authorizeManage(): void
    {
        abort_unless($this->canManage(), 403);
    }

    private function notify(string $message, string $type = 'success'): void
    {
        $this->dispatch('notify', message: $message, type: $type);
    }

    private function amountValue(): ?int
    {
        return filled($this->amount) ? (int) $this->amount : null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function paymentRules(): array
    {
        return [
            'amount' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function resetForm(): void
    {
        $this->reset(['plan', 'access_ends_at', 'amount', 'note', 'newPassword']);
        $this->extendDays = '30';
        $this->resetErrorBag();
    }
}
