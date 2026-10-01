<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

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

    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
