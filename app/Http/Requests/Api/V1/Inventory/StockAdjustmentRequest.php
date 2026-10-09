<?php

namespace App\Http\Requests\Api\V1\Inventory;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inventory.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', TenantRule::exists('products', 'id')->whereNull('deleted_at')],
            'type' => ['required', Rule::in(['stock_in', 'stock_out', 'opname'])],
            'quantity' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'unit_cost' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'required_if:type,stock_out', 'string', 'max:255'],
            'unit_id' => ['nullable', 'integer', TenantRule::exists('product_units', 'id')->where('product_id', $this->integer('product_id'))->whereNull('deleted_at')],
            'batch_number' => ['nullable', 'string', 'max:50'],
            'serials' => ['nullable', 'array', 'max:500'],
            'serials.*' => ['string', 'max:64'],
            'expires_at' => ['nullable', 'date'],
            'batch_id' => ['nullable', 'integer', TenantRule::exists('product_batches', 'id')->where('product_id', $this->integer('product_id'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required_if' => 'Tulis alasan stok keluar, mis. rusak, kedaluwarsa, dipakai sendiri.',
        ];
    }
}
