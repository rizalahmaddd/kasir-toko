<?php

namespace App\Http\Requests\Api\V1\Pos;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

class QuickCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pos.sell');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            'phone' => is_string($this->phone) ? (preg_replace('/[^0-9+]/', '', $this->phone) ?: null) : $this->phone,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30', TenantRule::unique('customers', 'phone')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['phone.unique' => 'Nomor HP ini sudah terdaftar. Cari pelanggannya di daftar.'];
    }
}
