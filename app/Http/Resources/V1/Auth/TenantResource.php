<?php

namespace App\Http\Resources\V1\Auth;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Toko tempat akun terdaftar beserta status langganannya. `blocked_reason` terisi
 * (tenant_suspended, trial_expired, subscription_expired) saat toko tidak bisa dipakai.
 *
 * @mixin Tenant
 */
class TenantResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, slug: string, plan: string, plan_label: string, status: string, access_ends_at: string|null, blocked_reason: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'plan' => $this->plan,
            'plan_label' => $this->planLabel(),
            'status' => $this->status,
            'access_ends_at' => $this->accessEndsAt()?->toIso8601String(),
            'blocked_reason' => $this->blockedReason(),
        ];
    }
}
