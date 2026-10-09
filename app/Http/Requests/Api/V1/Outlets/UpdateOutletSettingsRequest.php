<?php

namespace App\Http\Requests\Api\V1\Outlets;

use App\Enums\PaymentMethod;
use App\Support\PosSettings;
use App\Support\Qris;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateOutletSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('outlets.manage');
    }

    /**
     * Bagian yang tidak dikirim tidak berubah. `inherit` true menghapus penimpaan bagian itu.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tax' => ['sometimes', 'array'],
            'tax.inherit' => ['required_with:tax', 'boolean'],
            'tax.enabled' => ['nullable', 'boolean'],
            'tax.rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax.label' => ['nullable', 'string', 'max:20'],
            'service' => ['sometimes', 'array'],
            'service.inherit' => ['required_with:service', 'boolean'],
            'service.rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'service.dine_in_only' => ['nullable', 'boolean'],
            'payments' => ['sometimes', 'array'],
            'payments.inherit' => ['required_with:payments', 'boolean'],
            'payments.methods' => ['nullable', 'array'],
            'payments.methods.*' => [Rule::enum(PaymentMethod::class)],
            'receipt' => ['sometimes', 'array'],
            'receipt.inherit' => ['required_with:receipt', 'boolean'],
            'receipt.width' => ['nullable', Rule::in(['58', '80'])],
            'receipt.header' => ['nullable', 'string', 'max:300'],
            'receipt.footer' => ['nullable', 'string', 'max:300'],
            'receipt.auto_print' => ['nullable', 'boolean'],
            'qris' => ['sometimes', 'array'],
            'qris.inherit' => ['required_with:qris', 'boolean'],
            'qris.payload' => ['nullable', 'string', 'max:1000'],
            'rules' => ['sometimes', 'array'],
            'rules.inherit' => ['required_with:rules', 'boolean'],
            'rules.allow_credit' => ['nullable', 'boolean'],
            'rules.allow_negative_stock' => ['nullable', 'boolean'],
            'rules.quick_cash' => ['nullable', 'array', 'max:8'],
            'rules.quick_cash.*' => ['integer', 'min:1', 'max:100000000'],
            'pharmacy' => ['sometimes', 'array'],
            'pharmacy.inherit' => ['required_with:pharmacy', 'boolean'],
            'pharmacy.prescription_mode' => ['nullable', Rule::in(array_keys(PosSettings::PRESCRIPTION_MODES))],
            'pharmacy.allow_controlled_drugs' => ['nullable', 'boolean'],
            'pharmacy.block_expired_sale' => ['nullable', 'boolean'],
            'pharmacy.near_expiry_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:90'],
            'pharmacy.near_expiry_discount_days' => ['nullable', 'integer', 'min:0', 'max:60'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $qris = $this->input('qris');

            if (! is_array($qris) || ($qris['inherit'] ?? true) || blank($qris['payload'] ?? null)) {
                return;
            }

            $problem = Qris::problem((string) $qris['payload']) ?? (Qris::isDynamic((string) $qris['payload']) ? 'Ini QRIS dinamis sekali pakai. Pakai QRIS statis outlet.' : null);

            if ($problem) {
                $validator->errors()->add('qris.payload', $problem);
            }
        }];
    }
}
