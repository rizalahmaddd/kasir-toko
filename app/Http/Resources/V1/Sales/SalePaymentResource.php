<?php

namespace App\Http\Resources\V1\Sales;

use App\Http\Resources\V1\UserSummaryResource;
use App\Models\SalePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pembayaran. `kind` = `sale` untuk pembayaran saat transaksi, `receivable` untuk pelunasan kasbon.
 * Pembayaran tunai saat transaksi dicatat sebesar yang dipakai (tanpa kembalian).
 *
 * @mixin SalePayment
 */
class SalePaymentResource extends JsonResource
{
    /**
     * @return array{id: int, kind: string, method: string, method_label: string, amount: int, reference: string|null, user: UserSummaryResource|null, paid_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'amount' => $this->amount,
            'reference' => $this->reference,
            'user' => $this->whenLoaded('user', fn () => $this->user ? new UserSummaryResource($this->user) : null),
            'paid_at' => $this->paid_at->toIso8601String(),
        ];
    }
}
