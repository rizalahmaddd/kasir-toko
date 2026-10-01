<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Beranda aplikasi: ringkasan angka untuk akun ini. Hanya kartu yang boleh dilihat dan fiturnya
 * aktif yang dikirim; `target` menyebut daftar yang dibuka saat kartu diketuk.
 *
 * @property-read array<string, mixed> $resource
 */
class DashboardResource extends JsonResource
{
    /**
     * @return array{date: string, unread_notifications: int, stats: list<array{key: string, label: string, count: int, target: array{endpoint: string, query: array<string, string>}}>}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
