<?php

namespace App\Livewire\Platform;

use App\Livewire\Concerns\WithCrudActions;
use App\Models\Tenant;
use App\Services\TenantSubscriptionManager;
use App\Support\SaasPlans;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app', ['heading' => 'Toko Pelanggan'])]
#[Title('Toko Pelanggan')]
class Tenants extends Component
{
    use WithCrudActions;

    public const STATUS_FILTERS = [
        'usable' => 'Bisa dipakai',
        'ending' => 'Habis dalam 7 hari',
        'expired' => 'Masa aktif habis',
        Tenant::STATUS_SUSPENDED => 'Nonaktif',
    ];

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'paket')]
    public string $planFilter = '';

    public string $name = '';

    public string $plan = Tenant::PLAN_TRIAL;

    public string $status = Tenant::STATUS_ACTIVE;

    public string $access_ends_at = '';

    public string $amount = '';

    public string $note = '';

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingPlanFilter(): void
    {
        $this->resetPage();
    }

    public function save(TenantSubscriptionManager $subscriptions): void
    {
        $this->authorizeManage();

        $this->amount = preg_replace('/\D/', '', $this->amount) ?? '';
        $validated = $this->validate();
        $tenant = Tenant::query()->findOrFail($this->editingId);

        $subscriptions->update($tenant, [
            'name' => $validated['name'],
            'plan' => $validated['plan'],
            'status' => $validated['status'],
            'access_ends_at' => $validated['access_ends_at'] ? Carbon::parse($validated['access_ends_at']) : null,
        ], filled($validated['amount']) ? (int) $validated['amount'] : null, $validated['note']);

        $this->closeModal();
        $this->notify("Toko {$tenant->name} diperbarui.");
    }

    public function extend(int $id, int $days = 30): void
    {
        $this->authorizeManage();

        $tenant = Tenant::query()->findOrFail($id);
        app(TenantSubscriptionManager::class)->extend($tenant, $days);

        $this->notify("Masa aktif {$tenant->name} diperpanjang {$days} hari.");
    }

    /**
     * @return Builder<Tenant>
     */
    private function filteredQuery(): Builder
    {
        $query = Tenant::query()
            ->withCount('users')
            ->when($this->search, fn (Builder $query) => $query->where(fn (Builder $sub) => $sub->where('name', 'like', "%{$this->search}%")->orWhere('slug', 'like', "%{$this->search}%")))
            ->when($this->planFilter, fn (Builder $query) => $query->where('plan', $this->planFilter))
            ->when($this->statusFilter, fn (Builder $query) => match ($this->statusFilter) {
                // Nilai lama di URL (?status=active) tetap dikenali.
                'usable', Tenant::STATUS_ACTIVE => $query->where('status', Tenant::STATUS_ACTIVE)->whereNot(fn (Builder $sub) => $sub->expired()),
                'ending' => $query->where('status', Tenant::STATUS_ACTIVE)->accessEndsBetween(now(), now()->addDays(7)),
                'expired' => $query->where('status', Tenant::STATUS_ACTIVE)->expired(),
                Tenant::STATUS_SUSPENDED => $query->where('status', Tenant::STATUS_SUSPENDED),
                default => $query,
            });

        $this->applySorting($query, [
            'name' => 'name',
            'plan' => 'plan',
            'users_count' => 'users_count',
            'created_at' => 'created_at',
        ], 'created_at', 'desc');

        return $query;
    }

    public function export(string $format = 'xlsx')
    {
        $this->authorizeManage();

        $headers = ['Nama Toko', 'Slug', 'Jenis Toko', 'Paket', 'Aktif Sampai', 'Status', 'Pengguna', 'Terdaftar'];
        $rows = $this->filteredQuery()->lazy()->map(fn (Tenant $tenant) => [
            $tenant->name,
            $tenant->slug,
            $tenant->store_type?->label() ?? '-',
            $tenant->planLabel(),
            $tenant->accessEndsAt()?->format('d/m/Y') ?? 'Tanpa batas',
            match ($tenant->blockedReason()) {
                null => 'Aktif',
                'tenant_suspended' => 'Nonaktif',
                default => 'Masa aktif habis',
            },
            $tenant->users_count,
            $tenant->created_at?->format('d/m/Y'),
        ]);

        return $this->exportFormattedResponse('toko-pelanggan', $headers, $rows, 'Daftar Toko Pelanggan', null, $format);
    }

    public function render()
    {
        return view('livewire.platform.tenants', [
            'tenants' => $this->filteredQuery()->paginate($this->perPage),
            'plans' => SaasPlans::labels(),
            'statusFilters' => self::STATUS_FILTERS,
        ]);
    }

    public function canManage(): bool
    {
        return auth()->user()->can('manage-platform');
    }

    protected function modelClass(): string
    {
        return Tenant::class;
    }

    protected function resetForm(): void
    {
        $this->reset(['name', 'access_ends_at', 'amount', 'note']);
        $this->plan = Tenant::PLAN_TRIAL;
        $this->status = Tenant::STATUS_ACTIVE;
    }

    /**
     * @param  Tenant  $record
     */
    protected function fillForm($record): void
    {
        $this->name = $record->name;
        $this->plan = $record->plan;
        $this->status = $record->status;
        $this->access_ends_at = $record->accessEndsAt()?->toDateString() ?? '';
        $this->amount = '';
        $this->note = '';
    }

    /**
     * Toko tidak dihapus dari panel; data transaksinya tetap disimpan, cukup dinonaktifkan.
     */
    protected function guardDelete($record): ?string
    {
        return 'Toko tidak bisa dihapus. Ubah statusnya menjadi nonaktif.';
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'plan' => ['required', Rule::in(SaasPlans::keys())],
            'status' => ['required', Rule::in([Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED])],
            'access_ends_at' => ['nullable', 'date'],
            'amount' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
