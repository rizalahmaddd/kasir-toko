<?php

namespace App\Http\Resources\V1\Outlets;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Akun toko beserta status aksesnya ke satu outlet. `has_all_outlets` true untuk pemilik dan akun
 * "semua outlet": aksesnya tidak bisa dicabut dari outlet tertentu. `assigned` true bila ditugaskan
 * langsung ke outlet ini.
 *
 * @mixin User
 */
class OutletUserResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, username: string|null, has_all_outlets: bool, assigned: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'has_all_outlets' => $this->hasAllOutletAccess(),
            'assigned' => (bool) $this->getAttribute('assigned'),
        ];
    }
}
