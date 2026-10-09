<?php

namespace App\Http\Resources\V1\Auth;

use App\Http\Resources\V1\Outlets\OutletResource;
use App\Models\Outlet;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Features;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Permission;

/**
 * The signed-in account with everything the app needs to decide which menus to show: roles,
 * effective permissions, and the features that are switched on. `outlets` are the active outlets
 * this account may use and `current_outlet_id` the one the server would pick without an
 * `X-Outlet-Id` header. `app.update_required` is true when the shop has several outlets but the
 * client (`X-App-Version` header) is older than `app.min_supported_version`.
 * `enabled_features` is store-wide (any outlet uses it) and drives master data screens; `outlet_features`
 * is what is switched on at the current outlet and drives the cashier and operational menus.
 *
 * @mixin User
 */
class CurrentUserResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, username: string|null, email: string, phone: string|null, roles: list<string>, permissions: list<string>, is_superadmin: bool, all_outlets: bool, outlets: list<OutletResource>, current_outlet_id: int|null, enabled_features: list<string>, outlet_features: list<string>, app: array{min_supported_version: string, update_required: bool}, tenant: TenantResource|null}
     */
    public function toArray(Request $request): array
    {
        $current = app(CurrentOutlet::class);

        if (! $current->hasAccessLoaded() && $this->tenant_id !== null) {
            $current->loadAccess($this->resource);
        }

        $outlets = Outlet::query()->whereIn('id', $current->accessibleIds())->byPriority()->get();
        $minVersion = (string) config('saas.min_multi_outlet_app_version');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
            'roles' => $this->getRoleNames()->values()->all(),
            'permissions' => $this->isSuperAdmin()
                ? Permission::query()->orderBy('name')->pluck('name')->all()
                : $this->getAllPermissions()->pluck('name')->sort()->values()->all(),
            'is_superadmin' => $this->isSuperAdmin(),
            'all_outlets' => $this->hasAllOutletAccess(),
            'outlets' => OutletResource::collection($outlets),
            'current_outlet_id' => $current->id() ?? $current->pickDefault([$this->default_outlet_id]),
            'enabled_features' => self::enabledFeatures(),
            'outlet_features' => self::enabledFeatures(outletLevel: true),
            'app' => [
                'min_supported_version' => $minVersion,
                'update_required' => $current->isMultiOutlet() && version_compare((string) $request->header('X-App-Version', '0'), $minVersion, '<'),
            ],
            'tenant' => $this->tenant ? new TenantResource($this->tenant) : null,
        ];
    }

    /**
     * @return list<string>
     */
    public static function enabledFeatures(bool $outletLevel = false): array
    {
        $enabled = [];

        foreach (Features::MODULES as $module => $definition) {
            foreach (array_keys($definition['features']) as $feature) {
                if ($outletLevel ? Features::enabledAt("{$module}.{$feature}") : Features::enabled("{$module}.{$feature}")) {
                    $enabled[] = "{$module}.{$feature}";
                }
            }
        }

        return $enabled;
    }
}
