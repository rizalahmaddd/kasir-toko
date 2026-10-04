<?php

namespace App\Livewire\Sales;

use App\Enums\CashMovementType;
use App\Models\CashShift;
use App\Services\Pos\PosException;
use App\Services\Pos\ShiftService;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ShiftShow extends Component
{
    public CashShift $cashShift;

    public string $countedCash = '';

    public string $closingNote = '';

    public string $cashType = 'out';

    public string $cashAmount = '';

    public string $cashReason = '';

    public function mount(CashShift $cashShift): void
    {
        abort_unless($this->canAccess($cashShift), 403);

        $this->cashShift = $cashShift;
    }

    private function canAccess(CashShift $shift): bool
    {
        $user = auth()->user();

        return $shift->user_id === $user->id || $user->can('shifts.manage');
    }

    public function canOperate(): bool
    {
        return $this->cashShift->isOpen() && $this->canAccess($this->cashShift);
    }

    public function close(ShiftService $shifts): void
    {
        abort_unless($this->canOperate(), 403);

        $this->countedCash = preg_replace('/\D/', '', $this->countedCash) ?? '';
        $this->validate([
            'countedCash' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'closingNote' => ['nullable', 'string', 'max:255'],
        ], ['countedCash.required' => 'Hitung dan isi uang fisik di laci.']);

        $summary = $this->cashShift->summary();
        if ((int) $this->countedCash !== $summary['expected'] && trim($this->closingNote) === '') {
            $this->addError('closingNote', 'Ada selisih uang. Tulis penjelasannya supaya bisa dicek pemilik.');

            return;
        }

        try {
            $this->cashShift = $shifts->close($this->cashShift, (int) $this->countedCash, auth()->user(), trim($this->closingNote) ?: null);
        } catch (PosException $exception) {
            $this->addError('countedCash', $exception->getMessage());

            return;
        }

        $this->dispatch('close-modal', 'close-shift');
        $this->dispatch('notify', message: "Shift {$this->cashShift->number} ditutup.");
    }

    public function recordCash(ShiftService $shifts): void
    {
        abort_unless($this->canOperate(), 403);

        $this->cashAmount = preg_replace('/\D/', '', $this->cashAmount) ?? '';
        $this->validate([
            'cashType' => ['required', Rule::enum(CashMovementType::class)],
            'cashAmount' => ['required', 'integer', 'min:1'],
            'cashReason' => ['required', 'string', 'max:150'],
        ], ['cashReason.required' => 'Tulis keperluannya.']);

        try {
            $shifts->recordCash($this->cashShift, CashMovementType::from($this->cashType), (int) $this->cashAmount, $this->cashReason, auth()->user());
        } catch (PosException $exception) {
            $this->addError('cashAmount', $exception->getMessage());

            return;
        }

        $this->reset('cashAmount', 'cashReason');
        $this->dispatch('close-modal', 'cash-movement');
        $this->dispatch('notify', message: 'Catatan kas tersimpan.');
    }

    public function render()
    {
        $this->cashShift->load(['user', 'closer', 'cashMovements.user']);

        return view('livewire.sales.shift-show', [
            'summary' => $this->cashShift->summary(),
            'sales' => $this->cashShift->sales()->with('customer')->latest('sold_at')->limit(100)->get(),
        ])
            ->layout('layouts.app', ['heading' => 'Shift '.$this->cashShift->number])
            ->title('Shift '.$this->cashShift->number);
    }
}
