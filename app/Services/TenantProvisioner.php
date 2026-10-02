<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\SaasSettings;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Menyiapkan toko baru: baris tenant, peran bawaan beserta izinnya, identitas toko, dan akun
 * pemiliknya (peran superadmin di toko itu). Dipakai pendaftaran, app:install, dan seeder.
 */
class TenantProvisioner
{
    public function __construct(private CurrentTenant $currentTenant) {}

    /**
     * @param  array{name: string, username: string, email: string, password: string, phone?: string|null}  $owner
     * @return array{tenant: Tenant, owner: User}
     */
    public function provision(string $shopName, array $owner, ?string $plan = null): array
    {
        return DB::transaction(function () use ($shopName, $owner, $plan) {
            $tenant = Tenant::query()->create([
                'name' => $shopName,
                'slug' => Tenant::uniqueSlug($shopName),
                'plan' => $plan ?? Tenant::PLAN_TRIAL,
                'trial_ends_at' => now()->addDays(SaasSettings::trialDays()),
            ]);

            $user = $this->currentTenant->run($tenant, function () use ($shopName, $owner) {
                $this->seedRoles();
                Setting::put('company_name', $shopName);

                $user = User::query()->create($owner);
                $user->forceFill(['email_verified_at' => now()])->save();
                $user->assignRole('superadmin');

                return $user;
            });

            return ['tenant' => $tenant, 'owner' => $user];
        });
    }

    /**
     * Peran bawaan untuk tenant aktif. Izin itu katalog global, peran milik masing-masing toko.
     */
    public function seedRoles(): void
    {
        // Peran tanpa tenant ikut terbaca di semua toko (Spatie teams), jadi WAJIB ada tenant aktif.
        if ($this->currentTenant->id() === null) {
            throw new LogicException('Peran bawaan hanya bisa dibuat untuk tenant yang aktif.');
        }

        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $allPermissions = [];
        foreach (PermissionSeeder::PERMISSION_GROUPS as $permissions) {
            foreach (array_keys($permissions) as $permission) {
                Permission::findOrCreate($permission, 'web');
                $allPermissions[] = $permission;
            }
        }

        $registrar->forgetCachedPermissions();

        foreach (RoleSeeder::ROLES as $roleName) {
            $permissions = PermissionSeeder::DEFAULT_ROLE_PERMISSIONS[$roleName] ?? [];
            $role = Role::findOrCreate($roleName, 'web');

            $role->syncPermissions($permissions === ['*'] ? $allPermissions : $permissions);
        }
    }
}
