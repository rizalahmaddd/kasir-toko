<?php

namespace App\Http\Resources\V1\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array{otp_token: string, masked_phone: string, cooldown_seconds: int} $resource
 */
class OtpChallengeResource extends JsonResource
{
    /**
     * @return array{otp_token: string, masked_phone: string, cooldown_seconds: int}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
