<?php

namespace App\Http\Resources\V1\Pharmacy;

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resep obat. Jumlah obat (string desimal) dalam satuan dasar produk. `image_url` adalah endpoint API
 * berotorisasi (kirim token yang sama), bukan URL publik; null bila resep tanpa foto.
 *
 * @mixin Prescription
 */
class PrescriptionResource extends JsonResource
{
    /**
     * @return array{id: int, number: string, prescription_date: string, doctor_name: string, doctor_sip: string|null, clinic_name: string|null, patient_name: string, patient_age: int|null, patient_phone: string|null, patient_address: string|null, customer_id: int|null, status: string, status_label: string, is_verified: bool, verified_at: string|null, verified_by: string|null, outlet_id: int, notes: string|null, image_url: string|null, items: list<array{id: int, product_id: int|null, product_name: string, unit: string|null, quantity_prescribed: numeric-string, quantity_dispensed: numeric-string, remaining: numeric-string, iteration: int, dosage_instructions: string|null}>, created_at: string}
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['items.product', 'verifier']);

        return [
            'id' => $this->id,
            'number' => $this->number,
            'prescription_date' => $this->prescription_date->toDateString(),
            'doctor_name' => $this->doctor_name,
            'doctor_sip' => $this->doctor_sip,
            'clinic_name' => $this->clinic_name,
            'patient_name' => $this->patient_name,
            'patient_age' => $this->patient_age,
            'patient_phone' => $this->patient_phone,
            'patient_address' => $this->patient_address,
            'customer_id' => $this->customer_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_verified' => $this->isVerified(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verified_by' => $this->verifier?->name,
            'outlet_id' => $this->outlet_id,
            'notes' => $this->notes,
            'image_url' => $this->image_path ? route('api.v1.pharmacy.prescriptions.image.show', $this->id) : null,
            'items' => $this->items->map(fn (PrescriptionItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'unit' => $item->product?->unit,
                'quantity_prescribed' => number_format((float) $item->quantity_prescribed, 3, '.', ''),
                'quantity_dispensed' => number_format((float) $item->quantity_dispensed, 3, '.', ''),
                'remaining' => number_format($item->remaining(), 3, '.', ''),
                'iteration' => $item->iteration,
                'dosage_instructions' => $item->dosage_instructions,
            ])->values()->all(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
