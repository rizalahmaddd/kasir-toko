<?php

namespace App\Http\Resources\V1\Pos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * QRIS dinamis bernominal. Render `payload` sebagai QR code di aplikasi; nominalnya terkunci.
 *
 * @property-read array<string, mixed> $resource
 */
class QrisResource extends JsonResource
{
    /**
     * @return array{payload: string, amount: int, merchant_name: string, merchant_city: string}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
