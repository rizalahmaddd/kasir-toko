<?php

namespace App\Http\Requests\Api\V1\Sales;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceivablePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('receivables.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
