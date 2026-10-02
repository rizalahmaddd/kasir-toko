<?php

namespace App\Livewire\Platform;

use App\Livewire\Concerns\WithCrudActions;
use App\Models\Tenant;
use App\Services\TenantSubscriptionManager;
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

    #[Url(as: 'status')]
    public string $statusFilter = '';

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

    public function render()
    {
        $query = Tenant::query()
            ->withCount('users')
            ->when($this->search, fn ($query) => $query->where(fn ($sub) => $sub->where('name', 'like', "%{$this->search}%")->orWhere('slug', 'like', "%{$this->search}%")))
            ->when($this->statusFilter, fn ($query) => $query->where('status', $this->statusFilter));

        $this->applySorting($query, [
            'name' => 'name',
            'plan' => 'plan',
            'users_count' => 'users_count',
            'created_at' => 'created_at',
        ], 'created_at', 'desc');

        return view('livewire.platform.tenants', [
            'tenants' => $query->paginate($this->perPage),
            'plans' => collect(config('saas.plans'))->map(fn (array $plan) => $plan['label'])->all(),
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
            'plan' => ['required', Rule::in(array_keys(config('saas.plans')))],
            'status' => ['required', Rule::in([Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED])],
            'access_ends_at' => ['nullable', 'date'],
            'amount' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
