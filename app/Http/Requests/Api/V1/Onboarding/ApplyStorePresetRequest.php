<?php

namespace App\Http\Requests\Api\V1\Onboarding;

use App\Enums\StoreType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplyStorePresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isSuperAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'store_type' => ['required', Rule::enum(StoreType::class)],
            'include_sample_products' => ['boolean'],
            'categories' => ['sometimes', 'nullable', 'array'],
            'categories.*' => ['string', 'max:100'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'settings.tax_enabled' => ['sometimes', 'boolean'],
            'settings.tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'settings.tax_label' => ['sometimes', 'nullable', 'string', 'max:20'],
            'settings.allow_credit' => ['sometimes', 'boolean'],
            'settings.allow_negative_stock' => ['sometimes', 'boolean'],
            'settings.payment_methods' => ['sometimes', 'array'],
            'settings.payment_methods.*' => ['string'],
            'settings.receipt_footer' => ['sometimes', 'nullable', 'string', 'max:300'],
        ];
    }
}
