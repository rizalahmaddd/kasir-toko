<?php

namespace App\Http\Resources\V1\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Performa semua produk yang terjual di periode ini beserta totalnya.
 *
 * @property-read array<string, mixed> $resource
 */
class ProductSalesResource extends JsonResource
{
    /**
     * @return array{products: list<array{product_id: int|null, product_name: string, sku: string|null, unit: string, category_name: string, qty: float, revenue: int, cogs: int, profit: int, margin: float, avg_price: int}>, summary: array{qty: float, revenue: int, cogs: int, profit: int, margin: float}}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
