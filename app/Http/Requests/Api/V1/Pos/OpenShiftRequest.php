<?php

namespace App\Http\Requests\Api\V1\Pos;

use Illuminate\Foundation\Http\FormRequest;

class OpenShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pos.sell');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'opening_cash' => ['required', 'integer', 'min:0', 'max:999999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['opening_cash.required' => 'Isi modal awal laci, boleh 0.'];
    }
}
