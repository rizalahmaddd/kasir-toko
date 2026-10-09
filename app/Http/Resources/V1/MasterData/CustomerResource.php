<?php

namespace App\Http\Resources\V1\MasterData;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array{id: int, code: string, name: string, type: string|null, contact_person: string|null, phone: string|null, email: string|null, address: string|null, npwp: string|null, payment_term_days: int, credit_limit: int|null, is_active: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'npwp' => $this->npwp,
            'payment_term_days' => (int) $this->payment_term_days,
            'credit_limit' => $this->credit_limit,
            'is_active' => $this->is_active,
        ];
    }
}
