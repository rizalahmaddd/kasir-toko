<?php

namespace App\Http\Requests\Api\V1\Orders;

use App\Enums\PaymentMethod;
use App\Models\CustomerOrder;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('orders.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in([CustomerOrder::TYPE_ORDER, CustomerOrder::TYPE_SERVICE])],
            'customer_id' => ['nullable', 'integer', TenantRule::exists('customers', 'id')->whereNull('deleted_at')],
            'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:100'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'pickup_at' => ['nullable', 'date'],
            'device' => ['nullable', 'string', 'max:150'],
            'device_serial' => ['nullable', 'string', 'max:64'],
            'complaint' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array', 'max:50'],
            'items.*.product_id' => ['nullable', 'integer', TenantRule::exists('products', 'id')],
            'items.*.name' => ['nullable', 'string', 'max:150'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'items.*.price' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'items.*.note' => ['nullable', 'string', 'max:150'],
            'deposit' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'deposit_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'deposit_reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
