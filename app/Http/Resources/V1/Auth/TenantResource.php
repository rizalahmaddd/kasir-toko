<?php

namespace App\Http\Resources\V1\Auth;

use App\Models\Tenant;
use App\Support\PlanLimits;
use App\Support\SaasSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Toko tempat akun terdaftar beserta status langganannya. `blocked_reason` terisi
 * (tenant_suspended, trial_expired, subscription_expired) saat toko tidak bisa dipakai. Selama
 * `onboarded` false, pemilik toko belum memilih preset jenis toko (`store_type`) atau melewatinya.
 * `limits.outlets` berisi outlet aktif terpakai dan batas paket (`max`), `is_multi_outlet` true bila toko punya lebih dari satu outlet.
 * `renewal` hanya terisi saat toko terblokir: kontak admin layanan, cara bayar, dan harga paket.
 *
 * @mixin Tenant
 */
class TenantResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, slug: string, plan: string, plan_label: string, is_pro: bool, is_trial: bool, trial_ends_at: string|null, status: string, access_ends_at: string|null, blocked_reason: string|null, onboarded: bool, store_type: string|null, is_multi_outlet: bool, limits: array{outlets: array{used: int, max: int}}, renewal: array{contact: string|null, payment_instructions: string|null, plans: list<array{key: string, label: string, price: int}>}|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'plan' => $this->plan,
            'plan_label' => $this->planLabel(),
            'is_pro' => $this->isPro(),
            'is_trial' => $this->isOnTrial(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'status' => $this->status,
            'access_ends_at' => $this->accessEndsAt()?->toIso8601String(),
            'blocked_reason' => $this->blockedReason(),
            'onboarded' => $this->isOnboarded(),
            'store_type' => $this->store_type?->value,
            'is_multi_outlet' => $this->outlets()->count() > 1,
            'limits' => ['outlets' => ['used' => PlanLimits::count('outlets'), 'max' => $this->maxOutlets()]],
            'renewal' => $this->blockedReason() !== null ? SaasSettings::renewalInfo() : null,
        ];
    }
}
