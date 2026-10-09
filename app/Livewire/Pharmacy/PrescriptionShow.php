<?php

namespace App\Livewire\Pharmacy;

use App\Models\Prescription;
use App\Services\Pos\PosException;
use App\Services\Pos\PrescriptionService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app', ['heading' => 'Detail Resep'])]
class PrescriptionShow extends Component
{
    public Prescription $prescription;

    public function mount(Prescription $prescription): void
    {
        $this->prescription = $prescription;

        // Data pasien termasuk data pribadi (UU PDP), jadi setiap kali dibuka dicatat.
        activity('pharmacy')->performedOn($prescription)->causedBy(auth()->user())->event('viewed')
            ->log("Resep {$prescription->number} dibuka.");
    }

    public function verify(PrescriptionService $prescriptions): void
    {
        try {
            $prescriptions->verify($this->prescription, auth()->user());
        } catch (PosException $exception) {
            $this->dispatch('notify', message: $exception->getMessage(), type: 'error');

            return;
        }

        $this->dispatch('notify', message: "Resep {$this->prescription->number} diverifikasi.");
    }

    public function render(): View
    {
        $this->prescription->load(['items.product', 'outlet', 'creator', 'verifier', 'customer']);

        return view('livewire.pharmacy.prescription-show', [
            'sales' => $this->prescription->sales()->with('cashier')->latest('sold_at')->get(),
            'hasRemaining' => $this->prescription->items->contains(fn ($item) => $item->remaining() > 0),
        ])->title("Resep {$this->prescription->number}");
    }
}
