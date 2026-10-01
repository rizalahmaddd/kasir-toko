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
     * @return array{tax_rate: float, tax_label: string, allow_negative_stock: bool, allow_credit: bool, can_discount: bool, auto_print: bool, receipt_width: string, receipt_header: string, receipt_footer: string, quick_cash: list<int>, payment_methods: list<array{value: string, label: string, icon: string}>, qris_enabled: bool, has_open_shift: bool, held_orders_count: int}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
