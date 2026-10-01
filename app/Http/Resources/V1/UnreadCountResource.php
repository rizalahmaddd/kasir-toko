<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array{unread_count: int} $resource
 */
class UnreadCountResource extends JsonResource
{
    /**
     * @return array{unread_count: int}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
