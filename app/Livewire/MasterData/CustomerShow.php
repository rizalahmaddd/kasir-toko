<?php

namespace App\Livewire\MasterData;

use App\Models\Customer;
use App\Models\Sale;
use App\Support\Audit\AuditTrail;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class CustomerShow extends Component
{
    public Customer $customer;

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return ['echo-private:dashboard,.customer.changed' => 'refreshCustomer'];
    }

    public function refreshCustomer(): void
    {
        $this->customer = $this->customer->fresh() ?? $this->customer;
        unset($this->history);
    }

    public function mount(Customer $customer): void
    {
        $this->customer = $customer;
    }

    /**
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function history(): Collection
    {
        return Activity::query()
            ->with('causer')
            ->where('log_name', AuditTrail::LOG_NAME)
            ->whereMorphedTo('subject', $this->customer)
            ->latest('id')
            ->limit(10)
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Sale>
     */
    #[Computed]
    public function recentSales(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->customer->sales()->latest('sold_at')->limit(10)->get();
    }

    public function canViewSales(): bool
    {
        return auth()->user()->can('sales.view');
    }

    public function canViewActivityLog(): bool
    {
        return auth()->user()->can('viewAny', Activity::class);
    }

    public function render()
    {
        return view('livewire.master-data.customer-show')
            ->layout('layouts.app', ['heading' => $this->customer->name])
            ->title($this->customer->name);
    }
}
