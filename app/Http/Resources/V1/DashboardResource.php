<?php

namespace App\Http\Resources\V1;

use App\Http\Resources\V1\MasterData\ProductResource;
use App\Http\Resources\V1\Sales\SaleResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Beranda aplikasi: ringkasan angka untuk akun ini. Hanya kartu yang boleh dilihat dan fiturnya
 * aktif yang dikirim; `target` menyebut daftar yang dibuka saat kartu diketuk. `today.profit`
 * hanya untuk akun dengan izin laporan penjualan.
 *
 * @property-read array<string, mixed> $resource
 */
class DashboardResource extends JsonResource
{
    /**
     * @return array{date: string, unread_notifications: int, stats: list<array{key: string, label: string, count: int, target: array{endpoint: string, query: array<string, string>}}>, today: array{revenue: int, count: int, yesterday: int, profit: int|null}|null, receivables: array{total_due: int, count: int}|null, week_chart: list<array{label: string, value: int}>|null, low_stock: list<ProductResource>|null, recent_sales: list<SaleResource>|null}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
