<?php

namespace App\Livewire\Sales;

use App\Livewire\Concerns\WithDataTable;
use App\Models\CashShift;
use App\Models\User;
use App\Services\Pos\PosException;
use App\Services\Pos\ShiftService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app', ['heading' => 'Shift Kasir'])]
#[Title('Shift Kasir')]
class ShiftIndex extends Component
{
    use WithDataTable;

    #[Url]
    public string $status = '';

    #[Url]
    public string $cashier = '';

    public string $openingCash = '';

    public function updating(string $name): void
    {
        if (in_array($name, ['status', 'cashier'], true)) {
            $this->resetPage();
        }
    }

    public function canManageAll(): bool
    {
        return auth()->user()->can('shifts.manage');
    }

    #[Computed]
    public function myShift(): ?CashShift
    {
        return auth()->user()->openShift();
    }

    public function openShift(ShiftService $shifts): void
    {
        $this->openingCash = preg_replace('/\D/', '', $this->openingCash) ?? '';
        $this->validate(['openingCash' => ['required', 'integer', 'min:0', 'max:999999999']], ['openingCash.required' => 'Isi modal awal laci, boleh 0.']);

        try {
            $shift = $shifts->open(auth()->user(), (int) $this->openingCash);
        } catch (PosException $exception) {
            $this->addError('openingCash', $exception->getMessage());

            return;
        }

        $this->reset('openingCash');
        unset($this->myShift);
        $this->dispatch('close-modal', 'open-shift');
        $this->dispatch('notify', message: "Shift {$shift->number} dibuka.");
    }

    public function render()
    {
        $shifts = CashShift::query()
            ->with(['user', 'closer'])
            ->withCount(['sales as sales_count' => fn (Builder $query) => $query->completed()])
            ->withSum(['sales as sales_total' => fn (Builder $query) => $query->completed()], 'total')
            ->when(! $this->canManageAll(), fn (Builder $query) => $query->where('user_id', auth()->id()))
            ->when($this->canManageAll() && ctype_digit($this->cashier), fn (Builder $query) => $query->where('user_id', (int) $this->cashier))
            ->when($this->status === 'open', fn (Builder $query) => $query->whereNull('closed_at'))
            ->when($this->status === 'closed', fn (Builder $query) => $query->whereNotNull('closed_at'))
            ->when($this->status === 'variance', fn (Builder $query) => $query->whereNotNull('closed_at')->where('cash_difference', '!=', 0))
            ->latest('opened_at')
            ->paginate($this->perPage);

        return view('livewire.sales.shift-index', [
            'shifts' => $shifts,
            'summary' => $this->myShift?->summary(),
            'cashiers' => $this->canManageAll() ? User::query()->whereIn('id', CashShift::query()->select('user_id'))->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }
}
