<?php

namespace App\Http\Resources\V1\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ikhtisar laporan penjualan, sama dengan tab Ikhtisar di web. Laba kotor = omzet - pajak - HPP.
 * `*_growth` dibanding periode sebelumnya dengan panjang yang sama (null kalau periode sebelumnya
 * kosong). `chart` per hari untuk rentang ≤ 31 hari, per bulan untuk yang lebih panjang.
 *
 * @property-read array<string, mixed> $resource
 */
class SalesSummaryResource extends JsonResource
{
    /**
     * @return array{period: array{from: string, to: string, previous_from: string, previous_to: string}, totals: array{revenue: int, count: int, average: int, discount: int, discount_rate: float, tax: int, cogs: int, profit: int, margin: float, due: int, due_count: int, paid: int, voided: int, voided_total: int, void_rate: float, items: float, items_per_transaction: float, revenue_growth: float|null, profit_growth: float|null, count_growth: float|null, prev_revenue: int, prev_profit: int}, chart: array{metric: string, granularity: string, points: list<array{label: string, value: int}>}, payments: list<array{method: string, label: string, total: int, count: int}>, top_products: list<array{product_id: int|null, product_name: string, unit: string, category_name: string, qty: float, revenue: int, cogs: int, profit: int, margin: float}>, by_category: list<array{name: string, revenue: int, qty: float, profit: int}>, by_cashier: list<array{id: int, name: string, count: int, revenue: int, average: int}>, hourly: list<array{hour: string, label: string, count: int, revenue: int, is_peak: bool}>, top_customers: list<array{id: int, name: string, phone: string|null, count: int, revenue: int}>, customer_segments: array{member_revenue: int, member_count: int, member_percent: float, general_revenue: int, general_count: int, general_percent: float}}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
