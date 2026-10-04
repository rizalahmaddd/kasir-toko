<?php

namespace App\Http\Resources\V1\Pos;

use App\Http\Resources\V1\Sales\ShiftDetailResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shift yang sedang dibuka akun ini, atau null kalau belum buka shift (checkout akan ditolak
 * dengan reason `no_shift`).
 *
 * @property-read array<string, mixed> $resource
 */
class CurrentShiftResource extends JsonResource
{
    /**
     * @return array{shift: ShiftDetailResource|null}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
