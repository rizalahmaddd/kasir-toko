<?php

namespace App\Http\Resources\V1\Orders;

use App\Models\CustomerOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pesanan (`type` order) atau tiket servis (`type` service). `deposit` adalah uang muka bersih yang sudah
 * diterima; `remaining` = estimated_total − deposit (perkiraan; tagihan final dihitung kasir saat pelunasan).
 *
 * @mixin CustomerOrder
 */
class CustomerOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'type' => $this->type,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'pickup_at' => $this->pickup_at?->toIso8601String(),
            'estimated_total' => $this->estimated_total,
            'deposit' => $this->deposit,
            'remaining' => $this->remaining(),
            'device' => $this->device,
            'device_serial' => $this->device_serial,
            'complaint' => $this->complaint,
            'notes' => $this->notes,
            'sale_id' => $this->sale_id,
            'created_at' => $this->created_at->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->name,
                'quantity' => number_format((float) $item->quantity, 3, '.', ''),
                'price' => $item->price,
                'note' => $item->note,
            ])->values()->all()),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'kind' => $payment->kind,
                'method' => $payment->method->value,
                'amount' => $payment->amount,
                'reference' => $payment->reference,
                'paid_at' => $payment->paid_at->toIso8601String(),
            ])->values()->all()),
        ];
    }
}
