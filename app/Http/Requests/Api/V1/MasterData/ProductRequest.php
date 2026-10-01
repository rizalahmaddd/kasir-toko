<?php

namespace App\Http\Requests\Api\V1\MasterData;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-master-data');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->sku)) {
            $this->merge(['sku' => strtoupper(trim($this->sku))]);
        }
    }

    /**
     * `stock` is only read on create; later changes go through the stock adjustment endpoint so
     * every change lands on the stock card.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('product')?->id;

        return [
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'sku' => ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9._\-\/]+$/', Rule::unique('products', 'sku')->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('products', 'barcode')->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'unit' => ['required', 'string', 'max:20'],
            'cost_price' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'price' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'track_stock' => ['boolean'],
            'stock' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'min_stock' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sku.unique' => 'SKU ini sudah dipakai produk lain.',
            'sku.regex' => 'SKU hanya boleh huruf, angka, titik, garis bawah, strip, dan garis miring.',
            'barcode.unique' => 'Barcode ini sudah dipakai produk lain.',
            'price.required' => 'Harga jual wajib diisi.',
        ];
    }
}
