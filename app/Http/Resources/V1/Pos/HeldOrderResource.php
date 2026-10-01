<?php

namespace App\Http\Resources\V1\Pos;

use App\Models\HeldOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transaksi tertunda milik akun ini. `cart` hanya dikirim saat transaksi dilanjutkan (resume);
 * bentuknya sama dengan yang disimpan, jadi tertunda dari web bisa dilanjutkan di aplikasi dan
 * sebaliknya.
 *
 * @mixin HeldOrder
 */
class HeldOrderResource extends JsonResource
{
    public bool $withCart = false;

    /**
     * @return array{id: int, label: string, customer_id: int|null, item_count: int, total: int, created_at: string, cart?: array<string, mixed>}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'customer_id' => $this->customer_id,
            'item_count' => $this->item_count,
            'total' => $this->total,
            'created_at' => $this->created_at->toIso8601String(),
            'cart' => $this->when($this->withCart, fn () => $this->cart),
        ];
    }
}
