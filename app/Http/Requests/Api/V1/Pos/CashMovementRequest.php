<?php

namespace App\Http\Requests\Api\V1\Pos;

use App\Enums\CashMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashMovementRequest extends FormRequest
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
            'type' => ['required', Rule::in(array_column(CashMovementType::cases(), 'value'))],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999'],
            'reason' => ['required', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.required' => 'Tulis keperluannya, mis. beli es batu atau setor ke pemilik.'];
    }
}
