<?php

namespace App\Http\Resources\V1\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ringkasan stok produk aktif yang dilacak: jumlah produk, yang menipis, yang habis, dan nilai
 * stok (stok x HPP).
 *
 * @property-read array<string, int> $resource
 */
class StockSummaryResource extends JsonResource
{
    /**
     * @return array{tracked: int, low: int, out: int, value: int}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
