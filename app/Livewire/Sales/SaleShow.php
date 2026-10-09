<?php

namespace App\Livewire\Sales;

use App\Enums\PaymentMethod;
use App\Models\Sale;
use App\Services\Pos\DeliveryNoteService;
use App\Services\Pos\PosException;
use App\Services\Pos\SaleService;
use App\Support\Features;
use App\Support\PosSettings;
use App\Support\Receipt;
use Illuminate\Validation\Rule;
use Livewire\Component;

class SaleShow extends Component
{
    public Sale $sale;

    public string $voidReason = '';

    public string $payAmount = '';

    public string $payMethod = 'cash';

    public string $payReference = '';

    /**
     * @var array{recipient: string, phone: string, address: string, project: string, driver: string, vehicle: string, notes: string}
     */
    public array $delivery = ['recipient' => '', 'phone' => '', 'address' => '', 'project' => '', 'driver' => '', 'vehicle' => '', 'notes' => ''];

    public function mount(Sale $sale): void
    {
        $user = auth()->user();
        abort_unless($sale->user_id === $user->id || $user->can('sales.view'), 403);

        $this->sale = $sale;
    }

    public function canVoid(): bool
    {
        return ! $this->sale->isVoided() && auth()->user()->can('pos.void');
    }

    public function canCollect(): bool
    {
        return ! $this->sale->isVoided() && $this->sale->due_amount > 0 && auth()->user()->can('receivables.manage');
    }

    public function void(SaleService $sales): void
    {
        abort_unless($this->canVoid(), 403);

        $this->validate(['voidReason' => ['required', 'string', 'max:255']], ['voidReason.required' => 'Tulis alasan pembatalan.']);

        try {
            $this->sale = $sales->void($this->sale, auth()->user(), $this->voidReason);
        } catch (PosException $exception) {
            $this->addError('voidReason', $exception->getMessage());

            return;
        }

        $this->reset('voidReason');
        $this->dispatch('close-modal', 'void-sale');
        $this->dispatch('notify', message: "Transaksi {$this->sale->number} dibatalkan. Stok sudah dikembalikan.");
    }

    public function openCollect(): void
    {
        abort_unless($this->canCollect(), 403);

        $this->resetValidation();
        $this->payAmount = (string) $this->sale->due_amount;
        $this->payMethod = 'cash';
        $this->payReference = '';
        $this->dispatch('open-modal', 'collect-payment');
    }

    public function collect(SaleService $sales): void
    {
        abort_unless($this->canCollect(), 403);

        $this->payAmount = preg_replace('/\D/', '', $this->payAmount) ?? '';
        $validated = $this->validate([
            'payAmount' => ['required', 'integer', 'min:1'],
            'payMethod' => ['required', Rule::enum(PaymentMethod::class)],
            'payReference' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $sales->payReceivable($this->sale, auth()->user(), (int) $validated['payAmount'], PaymentMethod::from($validated['payMethod']), $validated['payReference'] ?: null);
        } catch (PosException $exception) {
            $this->addError('payAmount', $exception->getMessage());

            return;
        }

        $this->sale->refresh();
        $this->dispatch('close-modal', 'collect-payment');
        $this->dispatch('notify', message: $this->sale->due_amount > 0 ? 'Pembayaran dicatat. Masih ada sisa kasbon.' : 'Kasbon lunas.');
    }

    public function canDeliver(): bool
    {
        return ! $this->sale->isVoided() && Features::enabledAt('business.delivery-note', $this->sale->outlet_id) && auth()->user()->can('pos.sell');
    }

    public function openDelivery(): void
    {
        abort_unless($this->canDeliver(), 403);

        $this->resetValidation();
        $customer = $this->sale->customer;
        $this->delivery = ['recipient' => (string) $customer?->name, 'phone' => (string) $customer?->phone, 'address' => (string) $customer?->address, 'project' => (string) $this->sale->note, 'driver' => '', 'vehicle' => '', 'notes' => ''];
        $this->dispatch('open-modal', 'delivery-note');
    }

    public function createDelivery(DeliveryNoteService $notes): void
    {
        abort_unless($this->canDeliver(), 403);

        $validated = $this->validate(collect(DeliveryNoteService::rules())->mapWithKeys(fn ($rules, $key) => ["delivery.{$key}" => $rules])->all(), [
            'delivery.recipient.required' => 'Isi nama penerima.',
            'delivery.address.required' => 'Isi alamat kirim.',
        ])['delivery'];

        try {
            $note = $notes->create($this->sale, auth()->user(), array_map(fn ($value) => filled($value) ? $value : null, $validated));
        } catch (PosException $exception) {
            $this->addError('delivery.recipient', $exception->getMessage());

            return;
        }

        $this->dispatch('close-modal', 'delivery-note');
        $this->dispatch('notify', message: "Surat jalan {$note->number} dibuat.");
    }

    public function markDelivered(int $id, DeliveryNoteService $notes): void
    {
        abort_unless($this->canDeliver(), 403);

        $notes->markDelivered($this->sale->deliveryNotes()->findOrFail($id));
        $this->dispatch('notify', message: 'Surat jalan ditandai sudah diterima.');
    }

    public function render()
    {
        $this->sale->load(['items.product', 'items.serials', 'payments.user', 'cashier', 'customer', 'shift', 'voider', 'deliveryNotes', 'customerOrder']);

        return view('livewire.sales.sale-show', [
            'whatsappUrl' => Receipt::whatsappUrl($this->sale),
            'methods' => PosSettings::paymentMethods(),
        ])
            ->layout('layouts.app', ['heading' => 'Transaksi '.$this->sale->number])
            ->title($this->sale->number);
    }
}
