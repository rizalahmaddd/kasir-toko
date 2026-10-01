<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu kelompok hasil pencarian global (Pelanggan, Pengguna, ...).
 *
 * @property-read array<string, mixed> $resource
 */
class SearchResultResource extends JsonResource
{
    /**
     * @return array{group: string, icon: string, color: string, items: list<array{label: string, sub: string|null, flags: list<array{label: string, color: string}>, target: array{type: string, id: int}|null}>}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
