<?php

namespace App\Http\Resources\V1\Outlets;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pajak, layanan, metode bayar, struk, QRIS, aturan kasir (kasbon, stok minus, uang cepat), dan aturan
 * apotek satu outlet. Tiap bagian punya `inherit`: true berarti outlet
 * mengikuti pengaturan toko dan nilai di bagian itu adalah nilai toko; false berarti nilainya milik outlet.
 *
 * @property-read array<string, mixed> $resource
 */
class OutletSettingsResource extends JsonResource
{
    /**
     * @return array{tax: array{inherit: bool, enabled: bool, rate: string, label: string}, service: array{inherit: bool, rate: string, dine_in_only: bool}, payments: array{inherit: bool, methods: list<string>}, receipt: array{inherit: bool, width: string, header: string, footer: string, auto_print: bool}, qris: array{inherit: bool, payload: string}, rules: array{inherit: bool, allow_credit: bool, allow_negative_stock: bool, quick_cash: list<int>}, pharmacy: array{inherit: bool, prescription_mode: string, allow_controlled_drugs: bool, block_expired_sale: bool, near_expiry_discount_percent: string, near_expiry_discount_days: string}}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
