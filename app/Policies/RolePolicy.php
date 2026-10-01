<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    /**
     * Peran bawaan sistem yang tidak boleh dihapus atau diganti namanya.
     *
     * @var array<int, string>
     */
    public const PROTECTED_ROLES = [
        'superadmin',
        'admin',
        'staff',
    ];

    /**
     * Boleh melihat daftar peran dan matriks perizinan.
     */
    public function viewAny(User $user): bool
    {
        return $this->checkPermissionOrRole($user, 'roles.manage');
    }

    /**
     * Boleh membuat peran kustom baru.
     */
    public function create(User $user): bool
    {
        return $this->checkPermissionOrRole($user, 'roles.manage');
    }

    /**
     * Boleh mengubah data atau nama peran.
     */
    public function update(User $user, Role $role): bool
    {
        return $this->checkPermissionOrRole($user, 'roles.manage');
    }

    /**
     * Boleh menghapus peran (hanya peran non-sistem).
     */
    public function delete(User $user, Role $role): bool
    {
        if (in_array(strtolower($role->name), self::PROTECTED_ROLES, true)) {
            return false;
        }

        return $this->checkPermissionOrRole($user, 'roles.manage');
    }

    /**
     * Boleh mengelola hak akses perizinan suatu peran.
     */
    public function managePermissions(User $user, ?Role $role = null): bool
    {
        return $this->checkPermissionOrRole($user, 'roles.manage');
    }

    /**
     * Boleh menugaskan peran ke user.
     */
    public function manageUserRoles(User $user): bool
    {
        return $this->checkPermissionOrRole($user, 'users.manage');
    }

    /**
     * Cek perizinan secara aman terhadap database pengujian yang belum menjalankan seeder.
     */
    protected function checkPermissionOrRole(User $user, string $permission): bool
    {
        if ($user->hasRole('superadmin')) {
            return true;
        }

        try {
            return $user->hasPermissionTo($permission);
        } catch (\Throwable) {
            return false;
        }
    }
}
