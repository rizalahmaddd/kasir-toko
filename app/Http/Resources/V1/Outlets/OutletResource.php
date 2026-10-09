<?php

namespace App\Http\Resources\V1\Outlets;

use App\Models\Outlet;
use App\Support\CurrentOutlet;
use App\Support\CurrentTenant;
use App\Support\OutletFeatures;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Outlet (cabang) toko. `is_operational` false berarti outlet aktif tetapi terkunci batas paket:
 * datanya masih bisa dilihat, tetapi tidak bisa buka shift, checkout, atau mengubah stok.
 * `users_count` dan `user_ids` hanya ada di daftar lengkap dan detail outlet untuk pengelola.
 * `store_type` null berarti outlet mengikuti jenis usaha toko (`effective_store_type`). `capabilities`
 * adalah fitur khusus usaha yang menyala di outlet ini; kasir aplikasi memakai ini, bukan daftar toko.
 *
 * @mixin Outlet
 */
class OutletResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, code: string, address: string|null, phone: string|null, is_primary: bool, is_active: bool, is_operational: bool, priority: int, store_type: string|null, effective_store_type: string|null, effective_store_type_label: string|null, capabilities: list<string>, disabled_features: list<string>, users_count?: int, user_ids?: list<int>}
     */
    public function toArray(Request $request): array
    {
        $operational = $request->attributes->get('operational_outlet_ids');

        if ($operational === null) {
            $operational = app(CurrentOutlet::class)->tenantOperationalIds();
            $request->attributes->set('operational_outlet_ids', $operational);
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'phone' => $this->phone,
            'is_primary' => $this->is_primary,
            'is_active' => $this->is_active,
            'is_operational' => $this->is_active && in_array($this->id, $operational, true),
            'priority' => $this->priority,
            'store_type' => $this->store_type?->value,
            'effective_store_type' => ($type = $this->store_type ?? app(CurrentTenant::class)->get()?->store_type)?->value,
            'effective_store_type_label' => $type?->label(),
            'capabilities' => OutletFeatures::capabilities($this->id),
            'disabled_features' => $this->disabled_features ?? [],
            'users_count' => $this->whenCounted('users'),
            'user_ids' => $this->whenLoaded('users', fn () => $this->users->pluck('id')->map(fn ($id) => (int) $id)->values()->all()),
        ];
    }
}
