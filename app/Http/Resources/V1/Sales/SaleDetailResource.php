<?php

namespace App\Http\Resources\V1\Sales;

use App\Http\Controllers\Api\V1\Sales\DeliveryNoteController;
use App\Http\Resources\V1\Pos\KitchenTicketResource;
use App\Http\Resources\V1\UserSummaryResource;
use App\Models\Sale;
use App\Support\Receipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detail transaksi lengkap dengan barang, pembayaran, dan aksi yang boleh dilakukan akun ini.
 * `cash_received` adalah uang tunai yang diserahkan pembeli, `change_amount` kembaliannya. `flags` menandai
 * transaksi offline yang perlu ditinjau (mis. `prescription_unverified`, `expired_batch_sold`, `modifier_snapshot`).
 * `order_type`, `table_label`, `queue_number`, dan service charge diisi bila Tipe Pesanan & Meja / service charge dipakai.
 *
 * @mixin Sale
 */
class SaleDetailResource extends JsonResource
{
    /**
     * @return array{id: int, number: string, client_uuid: string|null, status: string, status_label: string, sold_at: string, outlet: array{id: int, name: string, code: string}|null, cashier: UserSummaryResource, customer: array{id: int, code: string, name: string, phone: string|null}|null, shift: array{id: int, number: string}|null, subtotal: int, discount_type: string|null, discount_value: numeric-string, discount_amount: int, tax_rate: numeric-string, tax_amount: int, service_charge_rate: numeric-string, service_charge_amount: int, order_type: string|null, order_type_label: string|null, table_label: string|null, queue_number: int|null, total: int, paid_amount: int, cash_received: int, change_amount: int, due_amount: int, note: string|null, items: list<SaleItemResource>, payments: list<SalePaymentResource>, voided_at: string|null, voided_by: UserSummaryResource|null, void_reason: string|null, prescription: array{id: int, number: string, doctor_name: string, patient_name: string|null}|null, customer_order: array{id: int, number: string}|null, delivery_notes: list<array<string, mixed>>, kitchen_tickets: list<array<string, mixed>>, flags: list<string>, flag_labels: list<string>, whatsapp_url: string, abilities: array{void: bool, collect_payment: bool}}
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $this->resource->loadMissing(['items.serials', 'payments.user', 'cashier', 'customer', 'shift', 'voider', 'outlet', 'prescription', 'deliveryNotes', 'customerOrder', 'kitchenTickets']);

        return [
            'id' => $this->id,
            'number' => $this->number,
            'client_uuid' => $this->client_uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'sold_at' => $this->sold_at->toIso8601String(),
            'outlet' => $this->outlet ? ['id' => $this->outlet->id, 'name' => $this->outlet->name, 'code' => $this->outlet->code] : null,
            'cashier' => new UserSummaryResource($this->cashier),
            'customer' => $this->customer ? [
                'id' => $this->customer->id,
                'code' => $this->customer->code,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ] : null,
            'shift' => $this->shift ? ['id' => $this->shift->id, 'number' => $this->shift->number] : null,
            'subtotal' => $this->subtotal,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'discount_amount' => $this->discount_amount,
            'tax_rate' => $this->tax_rate,
            'tax_amount' => $this->tax_amount,
            'service_charge_rate' => $this->service_charge_rate,
            'service_charge_amount' => $this->service_charge_amount,
            'order_type' => $this->order_type,
            'order_type_label' => $this->orderType()?->label(),
            'table_label' => $this->table_label,
            'queue_number' => $this->queue_number,
            'total' => $this->total,
            'paid_amount' => $this->paid_amount,
            'cash_received' => $this->cash_received,
            'change_amount' => $this->change_amount,
            'due_amount' => $this->due_amount,
            'note' => $this->note,
            'items' => SaleItemResource::collection($this->items),
            'payments' => SalePaymentResource::collection($this->payments),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'voided_by' => $this->voider ? new UserSummaryResource($this->voider) : null,
            'void_reason' => $this->void_reason,
            'prescription' => $this->prescription ? [
                'id' => $this->prescription->id,
                'number' => $this->prescription->number,
                'doctor_name' => $this->prescription->doctor_name,
                'patient_name' => $user->can('pharmacy.prescription.view') ? $this->prescription->patient_name : null,
            ] : null,
            'customer_order' => $this->customerOrder ? ['id' => $this->customerOrder->id, 'number' => $this->customerOrder->number] : null,
            'kitchen_tickets' => KitchenTicketResource::collection($this->kitchenTickets)->resolve($request),
            'delivery_notes' => $this->deliveryNotes->map(fn ($note) => DeliveryNoteController::payload($note))->values()->all(),
            'flags' => array_values($this->flags ?? []),
            'flag_labels' => $this->flagLabels(),
            'whatsapp_url' => Receipt::whatsappUrl($this->resource),
            'abilities' => [
                'void' => ! $this->isVoided() && $user->can('pos.void'),
                'collect_payment' => ! $this->isVoided() && $this->due_amount > 0 && $user->can('receivables.manage'),
            ],
        ];
    }
}
