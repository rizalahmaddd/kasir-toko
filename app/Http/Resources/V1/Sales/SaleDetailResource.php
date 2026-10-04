<?php

namespace App\Http\Resources\V1\Sales;

use App\Http\Resources\V1\UserSummaryResource;
use App\Models\Sale;
use App\Support\Receipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detail transaksi lengkap dengan barang, pembayaran, dan aksi yang boleh dilakukan akun ini.
 * `cash_received` adalah uang tunai yang diserahkan pembeli, `change_amount` kembaliannya.
 *
 * @mixin Sale
 */
class SaleDetailResource extends JsonResource
{
    /**
     * @return array{id: int, number: string, client_uuid: string|null, status: string, status_label: string, sold_at: string, cashier: UserSummaryResource, customer: array{id: int, code: string, name: string, phone: string|null}|null, shift: array{id: int, number: string}|null, subtotal: int, discount_type: string|null, discount_value: numeric-string, discount_amount: int, tax_rate: numeric-string, tax_amount: int, total: int, paid_amount: int, cash_received: int, change_amount: int, due_amount: int, note: string|null, items: list<SaleItemResource>, payments: list<SalePaymentResource>, voided_at: string|null, voided_by: UserSummaryResource|null, void_reason: string|null, whatsapp_url: string, abilities: array{void: bool, collect_payment: bool}}
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $this->resource->loadMissing(['items', 'payments.user', 'cashier', 'customer', 'shift', 'voider']);

        return [
            'id' => $this->id,
            'number' => $this->number,
            'client_uuid' => $this->client_uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'sold_at' => $this->sold_at->toIso8601String(),
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
            'whatsapp_url' => Receipt::whatsappUrl($this->resource),
            'abilities' => [
                'void' => ! $this->isVoided() && $user->can('pos.void'),
                'collect_payment' => ! $this->isVoided() && $this->due_amount > 0 && $user->can('receivables.manage'),
            ],
        ];
    }
}
