<?php

namespace App\Http\Requests\Api\V1\Inventory;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

class StockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inventory.transfer');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_outlet_id' => ['required', 'integer', TenantRule::exists('outlets', 'id')],
            'to_outlet_id' => ['required', 'integer', 'different:from_outlet_id', TenantRule::exists('outlets', 'id')],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.product_id' => ['required', 'integer', TenantRule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_outlet_id.different' => 'Outlet tujuan harus berbeda dengan outlet asal.',
            'items.required' => 'Tambahkan minimal satu produk.',
            'items.*.quantity.gt' => 'Jumlah harus lebih dari 0.',
        ];
    }
}
