<?php

namespace App\Http\Resources\V1\Sales;

use App\Http\Resources\V1\UserSummaryResource;
use App\Models\CashMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kas masuk/keluar laci di luar penjualan (mis. beli es batu, setor ke pemilik, refund).
 *
 * @mixin CashMovement
 */
class CashMovementResource extends JsonResource
{
    /**
     * @return array{id: int, type: string, type_label: string, amount: int, reason: string, user: UserSummaryResource|null, created_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'amount' => $this->amount,
            'reason' => $this->reason,
            'user' => $this->user ? new UserSummaryResource($this->user) : null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
