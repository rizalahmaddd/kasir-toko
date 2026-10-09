<?php

namespace App\Http\Requests\Api\V1\Outlets;

use App\Enums\StoreType;
use App\Services\OutletService;
use App\Support\Features;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OutletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('outlets.manage');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->code)) {
            $this->merge(['code' => strtoupper(trim($this->code))]);
        }
    }

    /**
     * `copy_from_outlet_id`, `include_sample_products`, dan `capabilities` hanya dibaca saat membuat outlet.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...OutletService::rules($this->route('outlet')),
            'copy_from_outlet_id' => ['nullable', 'integer', TenantRule::exists('outlets', 'id')],
            'store_type' => ['nullable', Rule::enum(StoreType::class)],
            'include_sample_products' => ['sometimes', 'boolean'],
            'capabilities' => ['sometimes', 'nullable', 'array'],
            'capabilities.*' => [Rule::in(Features::optInFeatures())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return OutletService::messages();
    }
}
