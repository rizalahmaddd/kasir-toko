<?php

namespace App\Http\Requests\Api\V1\Pharmacy;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

class PrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pharmacy.prescription.manage');
    }

    /**
     * Jumlah obat dalam satuan dasar produk, sudah termasuk iter.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prescription_date' => ['nullable', 'date', 'before_or_equal:today'],
            'doctor_name' => ['required', 'string', 'max:100'],
            'doctor_sip' => ['nullable', 'string', 'max:50'],
            'clinic_name' => ['nullable', 'string', 'max:150'],
            'patient_name' => ['required', 'string', 'max:100'],
            'patient_age' => ['nullable', 'integer', 'min:0', 'max:150'],
            'patient_phone' => ['nullable', 'string', 'max:30'],
            'patient_address' => ['nullable', 'string', 'max:255'],
            'customer_id' => ['nullable', 'integer', TenantRule::exists('customers', 'id')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:1000'],
            'verify' => ['boolean'],
            'image' => ['nullable', 'image', 'max:5120'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.product_id' => ['nullable', 'integer', TenantRule::exists('products', 'id')],
            'items.*.product_name' => ['required_without:items.*.product_id', 'nullable', 'string', 'max:150'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'items.*.iteration' => ['nullable', 'integer', 'min:0', 'max:10'],
            'items.*.dosage_instructions' => ['nullable', 'string', 'max:150'],
        ];
    }
}
