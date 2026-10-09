<?php

namespace App\Http\Resources\V1\Sales;

use App\Http\Resources\V1\UserSummaryResource;
use App\Models\CashShift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shift kasir. `expected_cash`, `counted_cash`, dan `cash_difference` baru terisi setelah shift
 * ditutup; selisih negatif berarti uang di laci kurang.
 *
 * @mixin CashShift
 */
class ShiftResource extends JsonResource
{
    /**
     * @return array{id: int, number: string, is_open: bool, outlet: array{id: int, name: string, code: string}|null, cashier: UserSummaryResource, opened_at: string, opening_cash: int, closed_at: string|null, closed_by: UserSummaryResource|null, expected_cash: int|null, counted_cash: int|null, cash_difference: int|null, closing_note: string|null, sales_count?: int, sales_total?: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'is_open' => $this->isOpen(),
            'outlet' => $this->outlet ? ['id' => $this->outlet->id, 'name' => $this->outlet->name, 'code' => $this->outlet->code] : null,
            'cashier' => new UserSummaryResource($this->user),
            'opened_at' => $this->opened_at->toIso8601String(),
            'opening_cash' => $this->opening_cash,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closed_by' => $this->closer ? new UserSummaryResource($this->closer) : null,
            'expected_cash' => $this->expected_cash,
            'counted_cash' => $this->counted_cash,
            'cash_difference' => $this->cash_difference,
            'closing_note' => $this->closing_note,
            'sales_count' => $this->when(array_key_exists('sales_count', $this->getAttributes()), fn () => (int) $this->sales_count),
            'sales_total' => $this->when(array_key_exists('sales_total', $this->getAttributes()), fn () => (int) $this->sales_total),
        ];
    }
}
