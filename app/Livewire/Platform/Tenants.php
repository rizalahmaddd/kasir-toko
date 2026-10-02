<?php

namespace App\Livewire\Platform;

use App\Livewire\Concerns\WithCrudActions;
use App\Models\Tenant;
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

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function save(): void
    {
        $this->authorizeManage();

        $validated = $this->validate();
        $tenant = Tenant::query()->findOrFail($this->editingId);
        $endsAt = $validated['access_ends_at'] ? Carbon::parse($validated['access_ends_at'])->endOfDay() : null;

        $tenant->update([
            'name' => $validated['name'],
            'plan' => $validated['plan'],
            'status' => $validated['status'],
            ...($validated['plan'] === Tenant::PLAN_TRIAL ? ['trial_ends_at' => $endsAt] : ['subscription_ends_at' => $endsAt]),
        ]);

        $this->closeModal();
        $this->notify("Toko {$tenant->name} diperbarui.");
    }

    /**
     * Perpanjangan dihitung dari akhir masa aktif yang masih berjalan, atau dari hari ini kalau sudah lewat.
     */
    public function extend(int $id, int $days = 30): void
    {
        $this->authorizeManage();

        $tenant = Tenant::query()->findOrFail($id);
        $column = $tenant->isOnTrial() ? 'trial_ends_at' : 'subscription_ends_at';
        $from = $tenant->accessEndsAt()?->isFuture() ? $tenant->accessEndsAt() : now();

        $tenant->update([$column => $from->copy()->addDays($days)->endOfDay()]);

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
        $this->reset(['name', 'access_ends_at']);
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
        ];
    }
}
