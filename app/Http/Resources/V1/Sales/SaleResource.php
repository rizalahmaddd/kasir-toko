<?php

namespace App\Http\Resources\V1\Sales;

use App\Http\Resources\V1\UserSummaryResource;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ringkasan transaksi untuk daftar. `due_amount` > 0 berarti masih ada kasbon.
 *
 * @mixin Sale
 */
class SaleResource extends JsonResource
{
    /**
     * @return array{id: int, number: string, status: string, status_label: string, sold_at: string, cashier: UserSummaryResource, customer: array{id: int, code: string, name: string, phone: string|null}|null, items_count: int, total: int, paid_amount: int, due_amount: int, payment_methods: list<string>}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
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
            'items_count' => (int) ($this->items_count ?? $this->items()->count()),
            'total' => $this->total,
            'paid_amount' => $this->paid_amount,
            'due_amount' => $this->due_amount,
            'payment_methods' => $this->payments->pluck('method')->map->value->unique()->values()->all(),
        ];
    }
}
