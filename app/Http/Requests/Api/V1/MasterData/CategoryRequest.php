<?php

namespace App\Http\Requests\Api\V1\MasterData;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

class CategoryRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:100', TenantRule::unique('categories', 'name')->ignore($this->route('category')?->id)],
            'sort_order' => ['integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
            'outlet_ids' => ['sometimes', 'array'],
            'outlet_ids.*' => ['integer', TenantRule::exists('outlets', 'id')],
        ];
    }
}
