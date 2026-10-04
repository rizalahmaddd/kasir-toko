<?php

namespace App\Http\Resources\V1\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rekap penjualan per hari (hanya hari yang ada transaksinya) beserta total periode.
 *
 * @property-read array<string, mixed> $resource
 */
class DailySalesResource extends JsonResource
{
    /**
     * @return array{days: list<array{date: string, count: int, qty: float, total: int, discount: int, tax: int, cogs: int, profit: int, margin: float}>, summary: array{count: int, qty: float, discount: int, tax: int, cogs: int, profit: int, margin: float, total: int}}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
