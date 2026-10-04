<?php

namespace App\Http\Resources\V1\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array{token: string, user: User} $resource
 */
class TokenResource extends JsonResource
{
    /**
     * @return array{token: string, token_type: string, user: CurrentUserResource}
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->resource['token'],
            'token_type' => 'Bearer',
            'user' => new CurrentUserResource($this->resource['user']),
        ];
    }
}
