<?php

namespace App\Http\Resources\V1\Auth;

use App\Models\User;
use App\Support\Features;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Permission;

/**
 * The signed-in account with everything the app needs to decide which menus to show: roles,
 * effective permissions, and the features that are switched on.
 *
 * @mixin User
 */
class CurrentUserResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, username: string|null, email: string, phone: string|null, roles: list<string>, permissions: list<string>, is_superadmin: bool, enabled_features: list<string>}
     */
    public function toArray(Request $request): array
    {
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
            'enabled_features' => self::enabledFeatures(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function enabledFeatures(): array
    {
        $enabled = [];

        foreach (Features::MODULES as $module => $definition) {
            foreach (array_keys($definition['features']) as $feature) {
                if (Features::enabled("{$module}.{$feature}")) {
                    $enabled[] = "{$module}.{$feature}";
                }
            }
        }

        return $enabled;
    }
}
