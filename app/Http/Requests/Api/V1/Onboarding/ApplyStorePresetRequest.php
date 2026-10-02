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
        ];
    }
}
