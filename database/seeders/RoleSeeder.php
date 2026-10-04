<?php

namespace Database\Seeders;

use App\Services\TenantProvisioner;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Peran bawaan. Superadmin melewati semua cek izin (Gate::before di AppServiceProvider);
     * peran lain mendapat izin dari PermissionSeeder::DEFAULT_ROLE_PERMISSIONS.
     */
    public const ROLES = [
        'superadmin',
        'admin',
        'kasir',
        'staff',
    ];

    /**
     * Peran dibuat untuk tenant yang sedang aktif (lihat TenantProvisioner).
     */
    public function run(): void
    {
        app(TenantProvisioner::class)->seedRoles();
    }
}
