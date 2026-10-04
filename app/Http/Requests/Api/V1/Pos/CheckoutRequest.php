<?php

namespace App\Http\Requests\Api\V1\Pos;

use App\Services\Pos\SaleService;
use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
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
        return SaleService::checkoutRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return SaleService::checkoutMessages();
    }
}
