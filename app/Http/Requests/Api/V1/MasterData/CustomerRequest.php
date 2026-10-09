<?php

namespace App\Http\Requests\Api\V1\MasterData;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-master-data');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('customer')?->id;

        return [
            'code' => ['required', 'string', 'max:50', TenantRule::unique('customers', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['nullable', 'string', 'max:100'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'npwp' => ['nullable', 'string', 'max:30'],
            'payment_term_days' => ['required', 'integer', 'min:0'],
            'credit_limit' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'is_active' => ['boolean'],
        ];
    }
}
