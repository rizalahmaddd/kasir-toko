<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Database\Seeders\PermissionSeeder;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SyncRolePermissions extends Command
{
    /**
     * Izin yang ditambahkan fitur outlet; toko lama tidak mendapatkannya dari TenantProvisioner::seedRoles().
     */
    public const OUTLET_PERMISSIONS = ['outlets.view', 'outlets.manage', 'inventory.transfer', 'reports.all-outlets'];

    public const PHARMACY_PERMISSIONS = ['pharmacy.prescription.view', 'pharmacy.prescription.manage', 'pharmacy.prescription.verify'];

    public const KITCHEN_PERMISSIONS = ['kitchen.view'];

    public const ORDER_PERMISSIONS = ['orders.manage'];

    public const STOCK_COUNT_PERMISSIONS = ['inventory.opname.count', 'inventory.opname.manage', 'reports.stock.view'];

    protected $signature = 'saas:sync-role-permissions
                            {permission?* : Izin yang ditambahkan ke peran bawaan (default: izin outlet)}';

    protected $description = 'Tambahkan izin baru ke peran bawaan di setiap toko tanpa mencabut izin hasil kustomisasi pemilik.';

    public function handle(PermissionRegistrar $registrar): int
    {
        $permissions = $this->argument('permission') ?: self::OUTLET_PERMISSIONS;

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $registrar->forgetCachedPermissions();
        $granted = 0;

        foreach (Tenant::query()->pluck('id') as $tenantId) {
            setPermissionsTeamId($tenantId);

            foreach (PermissionSeeder::DEFAULT_ROLE_PERMISSIONS as $roleName => $defaults) {
                if ($defaults === ['*']) {
                    continue;
                }

                $role = Role::query()->where('tenant_id', $tenantId)->where('name', $roleName)->where('guard_name', 'web')->first();
                $missing = $role ? array_values(array_diff(array_intersect($permissions, $defaults), $role->permissions->pluck('name')->all())) : [];

                if ($missing !== []) {
                    $role->givePermissionTo($missing);
                    $granted += count($missing);
                }
            }
        }

        setPermissionsTeamId(null);
        $registrar->forgetCachedPermissions();
        $this->info("Selesai. {$granted} izin ditambahkan ke peran bawaan.");

        return self::SUCCESS;
    }
}
