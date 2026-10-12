<?php

namespace App\Http\Resources\V1\Pos;

use App\Models\SelfOrder;
use App\Models\SelfOrderItem;
use App\Services\Pos\SelfOrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pesanan yang dikirim pelanggan dari QR. `next_action` adalah langkah kasir berikutnya: `accept` (masuk
 * open bill), `pay` (ambil `cart` lalu checkout), `verify` (QRIS menunggu verifikasi), `decide` (QRIS
 * ditolak: tagih di kasir atau batalkan), atau null bila tinggal menunggu dapur / diantar.
 *
 * @mixin SelfOrder
 */
class SelfOrderResource extends JsonResource
{
    /**
     * @return array{id: int, number: string, label: string, order_type: string, table_label: string|null, queue_number: int|null, customer_name: string, customer_phone: string|null, status: string, status_label: string, payment_method: string, payment_status: string, payment_label: string, next_action: string|null, subtotal: int, service_charge_amount: int, tax_amount: int, total: int, notes: string|null, held_order_id: int|null, sale_id: int|null, items: list<array{id: int, product_id: int, name: string, quantity: float, price: int, modifiers: list<string>, note: string|null, total: int}>, created_at: string, ready_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'label' => $this->label(),
            'order_type' => $this->order_type,
            'table_label' => $this->table_label,
            'queue_number' => $this->queue_number,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'payment_label' => $this->paymentLabel(),
            'next_action' => app(SelfOrderService::class)->nextAction($this->resource),
            'subtotal' => $this->subtotal,
            'service_charge_amount' => $this->service_charge_amount,
            'tax_amount' => $this->tax_amount,
            'total' => $this->total,
            'notes' => $this->notes,
            'held_order_id' => $this->held_order_id,
            'sale_id' => $this->sale_id,
            'items' => $this->items->map(fn (SelfOrderItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->product_name,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'modifiers' => collect($item->modifiers ?? [])->pluck('name')->filter()->values()->all(),
                'note' => $item->note,
                'total' => $item->total,
            ])->all(),
            'created_at' => $this->created_at->toIso8601String(),
            'ready_at' => $this->ready_at?->toIso8601String(),
        ];
    }
}
