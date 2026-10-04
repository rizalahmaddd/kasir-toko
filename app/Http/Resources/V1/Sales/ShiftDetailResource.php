<?php

namespace App\Http\Resources\V1\Sales;

use App\Http\Resources\V1\UserSummaryResource;
use App\Models\CashShift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shift beserta rekap laci. `summary.expected` = modal + tunai penjualan + tunai pelunasan + kas
 * masuk - kas keluar; `summary.non_cash` berisi total per metode non-tunai.
 *
 * @mixin CashShift
 */
class ShiftDetailResource extends JsonResource
{
    /**
     * @return array{id: int, number: string, is_open: bool, cashier: UserSummaryResource, opened_at: string, opening_cash: int, closed_at: string|null, closed_by: UserSummaryResource|null, expected_cash: int|null, counted_cash: int|null, cash_difference: int|null, closing_note: string|null, summary: array{opening: int, cash_sales: int, cash_receivables: int, cash_in: int, cash_out: int, expected: int, sales_count: int, sales_total: int, voided_count: int, non_cash: array<string, int>}, cash_movements: list<CashMovementResource>, abilities: array{close: bool, record_cash: bool}}
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $this->resource->loadMissing(['user', 'closer', 'cashMovements.user']);
        $canOperate = $this->isOpen() && ($this->user_id === $user->id || $user->can('shifts.manage'));

        return [
            ...(new ShiftResource($this->resource))->toArray($request),
            'summary' => $this->summary(),
            'cash_movements' => CashMovementResource::collection($this->cashMovements),
            'abilities' => [
                'close' => $canOperate,
                'record_cash' => $canOperate,
            ],
        ];
    }
}
