<?php

namespace App\Http\Resources\V1\Pos;

use App\Enums\OrderType;
use App\Models\KitchenTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tiket dapur tanpa harga. `items` snapshot saat dikirim: `{name, quantity, unit, modifiers: list<string>, note}`.
 *
 * @mixin KitchenTicket
 */
class KitchenTicketResource extends JsonResource
{
    /**
     * @return array{id: int, label: string, order_type: string|null, order_type_label: string|null, status: string, items: list<array<string, mixed>>, sale_number: string|null, cashier: string|null, created_at: string, done_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'order_type' => $this->order_type,
            'order_type_label' => OrderType::tryFrom((string) $this->order_type)?->label(),
            'status' => $this->status,
            'items' => array_values($this->items ?? []),
            'sale_number' => $this->relationLoaded('sale') ? $this->sale?->number : null,
            'cashier' => $this->relationLoaded('user') ? $this->user?->name : null,
            'created_at' => $this->created_at->toIso8601String(),
            'done_at' => $this->done_at?->toIso8601String(),
        ];
    }
}
