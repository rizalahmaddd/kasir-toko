<?php

namespace App\Http\Requests\Api\V1\Pos;

use Illuminate\Foundation\Http\FormRequest;

class HoldOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pos.sell');
    }

    /**
     * The cart is stored as-is and handed back on resume. These are the fields the web cashier
     * reads, so a cart held on one device can be resumed on the other.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:60'],
            'cart' => ['required', 'array'],
            'cart.items' => ['required', 'array', 'min:1', 'max:300'],
            'cart.items.*.product_id' => ['required', 'integer'],
            'cart.items.*.name' => ['required', 'string', 'max:150'],
            'cart.items.*.sku' => ['nullable', 'string', 'max:50'],
            'cart.items.*.unit' => ['nullable', 'string', 'max:20'],
            'cart.items.*.price' => ['required', 'integer', 'min:0'],
            'cart.items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'cart.items.*.discount' => ['nullable', 'integer', 'min:0'],
            'cart.items.*.note' => ['nullable', 'string', 'max:150'],
            'cart.items.*.track' => ['nullable', 'boolean'],
            'cart.items.*.stock' => ['nullable', 'numeric'],
            'cart.customer' => ['nullable', 'array'],
            'cart.customer.id' => ['nullable', 'integer'],
            'cart.customer.name' => ['nullable', 'string', 'max:150'],
            'cart.discountType' => ['nullable', 'in:percent,amount'],
            'cart.discountValue' => ['nullable', 'numeric', 'min:0'],
            'cart.note' => ['nullable', 'string', 'max:255'],
            'cart.total' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['cart.items.required' => 'Keranjang masih kosong.', 'cart.items.min' => 'Keranjang masih kosong.'];
    }
}
