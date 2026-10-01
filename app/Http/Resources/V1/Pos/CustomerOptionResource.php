<?php

namespace App\Http\Resources\V1\Pos;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pelanggan untuk dipilih di kasir, beserta total kasbon yang belum lunas (`due`).
 *
 * @mixin Customer
 */
class CustomerOptionResource extends JsonResource
{
    /**
     * @return array{id: int, code: string, name: string, phone: string|null, due: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'phone' => $this->phone,
            'due' => (int) ($this->due ?? 0),
        ];
    }
}
