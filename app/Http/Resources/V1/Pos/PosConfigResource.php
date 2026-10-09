<?php

namespace App\Http\Resources\V1\Pos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pengaturan layar kasir untuk akun ini. `tax_rate` sudah 0 kalau pajak dimatikan. Hitung total
 * dengan rumus yang sama dengan server (lihat deskripsi checkout) lalu kirim sebagai
 * `expected_total`.
 *
 * @property-read array<string, mixed> $resource
 */
class PosConfigResource extends JsonResource
{
    /**
     * @return array{outlet_id: int|null, outlet_name: string|null, is_multi_outlet: bool, outlet_locked: bool, shift_outlet_id: int|null, tax_rate: float, tax_label: string, allow_negative_stock: bool, allow_credit: bool, can_discount: bool, auto_print: bool, receipt_width: string, receipt_header: string, receipt_footer: string, quick_cash: list<int>, payment_methods: list<array{value: string, label: string, icon: string}>, qris_enabled: bool, has_open_shift: bool, block_expired_sale: bool, expiry_warning_days: int, prescription_mode: string, allow_controlled_drugs: bool, can_verify_prescription: bool, held_orders_count: int, service_charge_rate: float, service_charge_dine_in_only: bool, order_type_enabled: bool, modifiers_enabled: bool, tiered_price_enabled: bool, variants_enabled: bool, near_expiry_discount_percent: float, serials_enabled: bool, pre_order_enabled: bool, delivery_note_enabled: bool}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
