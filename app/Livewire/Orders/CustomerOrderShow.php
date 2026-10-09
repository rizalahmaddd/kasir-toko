<?php

namespace App\Livewire\Orders;

use App\Enums\CustomerOrderStatus;
use App\Enums\PaymentMethod;
use App\Models\CustomerOrder;
use App\Services\Pos\CustomerOrderService;
use App\Services\Pos\PosException;
use App\Support\PosSettings;
use Illuminate\Validation\Rule;
use Livewire\Component;

class CustomerOrderShow extends Component
{
    public CustomerOrder $order;

    public string $payAmount = '';

    public string $payMethod = 'cash';

    public string $payReference = '';

    public bool $refund = true;

    public string $cancelReason = '';

    public function mount(CustomerOrder $order): void
    {
        abort_unless(auth()->user()->can('orders.manage'), 403);

        $this->order = $order;
    }

    public function setStatus(string $status, CustomerOrderService $orders): void
    {
        try {
            $orders->setStatus($this->order, CustomerOrderStatus::from($status));
        } catch (PosException $exception) {
            $this->dispatch('notify', message: $exception->getMessage(), type: 'error');

            return;
        }

        $this->dispatch('notify', message: "Status diubah menjadi {$this->order->status->label()}.");
    }

    public function openPay(): void
    {
        $this->resetValidation();
        $this->payAmount = '';
        $this->payMethod = PaymentMethod::Cash->value;
        $this->payReference = '';
        $this->dispatch('open-modal', 'order-pay');
    }

    public function pay(CustomerOrderService $orders): void
    {
        $this->payAmount = preg_replace('/\D/', '', $this->payAmount) ?? '';
        $validated = $this->validate([
            'payAmount' => ['required', 'integer', 'min:1', 'max:999999999'],
            'payMethod' => ['required', Rule::enum(PaymentMethod::class)],
            'payReference' => ['nullable', 'string', 'max:100'],
        ], ['payAmount.required' => 'Isi nominal uang muka.']);

        try {
            $orders->pay($this->order, auth()->user(), (int) $validated['payAmount'], PaymentMethod::from($validated['payMethod']), $validated['payReference'] ?: null);
        } catch (PosException $exception) {
            $this->addError('payAmount', $exception->getMessage());

            return;
        }

        $this->order->refresh();
        $this->dispatch('close-modal', 'order-pay');
        $this->dispatch('notify', message: 'Uang muka dicatat.');
    }

    public function cancel(CustomerOrderService $orders): void
    {
        $this->validate(['cancelReason' => ['required', 'string', 'max:255']], ['cancelReason.required' => 'Tulis alasan pembatalan.']);

        try {
            $this->order = $orders->cancel($this->order, auth()->user(), $this->refund, $this->cancelReason);
        } catch (PosException $exception) {
            $this->addError('cancelReason', $exception->getMessage());

            return;
        }

        $this->dispatch('close-modal', 'order-cancel');
        $this->dispatch('notify', message: "Pesanan {$this->order->number} dibatalkan.");
    }

    public function render()
    {
        $this->order->load(['items', 'payments.user', 'creator', 'sale', 'customer']);

        return view('livewire.orders.customer-order-show', [
            'methods' => PosSettings::paymentMethods(),
            'canSettle' => $this->order->isOpen() && auth()->user()->can('pos.sell'),
        ])
            ->layout('layouts.app', ['heading' => ($this->order->isService() ? 'Servis ' : 'Pesanan ').$this->order->number])
            ->title($this->order->number);
    }
}
