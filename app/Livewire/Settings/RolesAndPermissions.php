<?php

namespace App\Livewire\Settings;

use App\Models\User;
use App\Policies\RolePolicy;
use App\Support\CurrentTenant;
use App\Support\PlanLimits;
use App\Support\TenantRule;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

#[Layout('layouts.app', ['heading' => 'Pengaturan Peran & Perizinan'])]
#[Title('Pengaturan Peran & Perizinan')]
class RolesAndPermissions extends Component
{
    use WithPagination;

    public const TABS = ['roles', 'matrix', 'users'];

    #[Url(as: 'tab', except: 'roles')]
    public string $tab = 'roles';

    #[Url(as: 'peran', except: '')]
    public string $selectedRoleName = 'superadmin';

    public array $rolePermissions = [];

    public string $permissionSearch = '';

    public string $roleSearch = '';

    public string $matrixSearch = '';

    public string $matrixModule = '';

    #[Url(as: 'cari', except: '')]
    public string $userSearch = '';

    public string $userRoleFilter = '';

    // Modals
    public bool $showCreateRoleModal = false;

    public string $newRoleName = '';

    public bool $showEditRoleModal = false;

    public ?int $editingRoleId = null;

    public string $editingRoleName = '';

    public bool $showDeleteRoleModal = false;

    public ?int $roleToDeleteId = null;

    public bool $showUserRolesModal = false;

    public ?int $editingUserId = null;

    public array $editingUserRoles = [];

    public string $newUserName = '';

    public string $newUserUsername = '';

    public string $newUserEmail = '';

    public string $newUserPhone = '';

    public string $newUserPassword = '';

    /** @var list<string> */
    public array $newUserRoles = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('open', Role::class), 403);

        if (! in_array($this->tab, $this->availableTabs(), true)) {
            $this->tab = $this->availableTabs()[0];
        }

        $roles = $this->roles()->orderByRaw("CASE WHEN LOWER(name) = 'superadmin' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->pluck('name')
            ->all();

        if (! in_array($this->selectedRoleName, $roles, true)) {
            $this->selectedRoleName = $roles[0] ?? 'superadmin';
        }

        $this->loadSelectedRolePermissions();
    }

    public function updatedTab(): void
    {
        if (! in_array($this->tab, $this->availableTabs(), true)) {
            $this->tab = $this->availableTabs()[0];
        }

        $this->resetPage();
    }

    /**
     * @return list<string>
     */
    public function availableTabs(): array
    {
        return Auth::user()->can('viewAny', Role::class) ? self::TABS : ['users'];
    }

    public function updatedUserSearch(): void
    {
        $this->resetPage();
    }

    public function updatedUserRoleFilter(): void
    {
        $this->resetPage();
    }

    public function selectRole(string $roleName): void
    {
        $this->selectedRoleName = $roleName;
        $this->loadSelectedRolePermissions();
    }

    public function loadSelectedRolePermissions(): void
    {
        $role = $this->roles()->where('name', $this->selectedRoleName)->first();

        if ($role) {
            $this->rolePermissions = $role->permissions()->pluck('name')->all();
        } else {
            $this->rolePermissions = [];
        }
    }

    public function toggleRolePermission(string $permissionName): void
    {
        abort_unless(Auth::user()->can('managePermissions', Role::class), 403);

        $role = $this->roles()->where('name', $this->selectedRoleName)->firstOrFail();
        $permissionsBefore = $this->permissionNamesOf($role);

        if (in_array($permissionName, $this->rolePermissions, true)) {
            $this->rolePermissions = array_values(array_diff($this->rolePermissions, [$permissionName]));
            $role->revokePermissionTo($permissionName);
            $action = 'dicabut dari';
        } else {
            $this->rolePermissions[] = $permissionName;
            $role->givePermissionTo($permissionName);
            $action = 'diberikan kepada';
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->logPermissionChange($role, $permissionsBefore, "Hak akses [{$permissionName}] {$action} peran '{$role->name}'.");

        $this->dispatch('notify', message: "Hak akses [{$permissionName}] berhasil diperbarui.");
    }

    public function selectAllInModule(string $module): void
    {
        abort_unless(Auth::user()->can('managePermissions', Role::class), 403);

        $role = $this->roles()->where('name', $this->selectedRoleName)->firstOrFail();
        $permissionsBefore = $this->permissionNamesOf($role);
        $modulePermissions = array_keys(PermissionSeeder::PERMISSION_GROUPS[$module] ?? []);

        $newPermissions = array_values(array_unique(array_merge($this->rolePermissions, $modulePermissions)));
        $role->syncPermissions($newPermissions);
        $this->rolePermissions = $newPermissions;

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->logPermissionChange($role, $permissionsBefore, "Semua hak akses modul '{$module}' diberikan kepada peran '{$role->name}'.");

        $this->dispatch('notify', message: "Semua hak akses modul {$module} diaktifkan.");
    }

    public function clearAllInModule(string $module): void
    {
        abort_unless(Auth::user()->can('managePermissions', Role::class), 403);

        $role = $this->roles()->where('name', $this->selectedRoleName)->firstOrFail();
        $permissionsBefore = $this->permissionNamesOf($role);
        $modulePermissions = array_keys(PermissionSeeder::PERMISSION_GROUPS[$module] ?? []);

        $newPermissions = array_values(array_diff($this->rolePermissions, $modulePermissions));
        $role->syncPermissions($newPermissions);
        $this->rolePermissions = $newPermissions;

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->logPermissionChange($role, $permissionsBefore, "Semua hak akses modul '{$module}' dicabut dari peran '{$role->name}'.");

        $this->dispatch('notify', message: "Semua hak akses modul {$module} dinonaktifkan.");
    }

    public function selectAllPermissions(): void
    {
        abort_unless(Auth::user()->can('managePermissions', Role::class), 403);

        $role = $this->roles()->where('name', $this->selectedRoleName)->firstOrFail();
        $permissionsBefore = $this->permissionNamesOf($role);
        $allPermissions = Permission::pluck('name')->all();

        $role->syncPermissions($allPermissions);
        $this->rolePermissions = $allPermissions;

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->logPermissionChange($role, $permissionsBefore, "Seluruh hak akses sistem diberikan kepada peran '{$role->name}'.");

        $this->dispatch('notify', message: 'Semua hak akses sistem berhasil diaktifkan.');
    }

    public function clearAllPermissions(): void
    {
        abort_unless(Auth::user()->can('managePermissions', Role::class), 403);

        $role = $this->roles()->where('name', $this->selectedRoleName)->firstOrFail();
        $permissionsBefore = $this->permissionNamesOf($role);

        $role->syncPermissions([]);
        $this->rolePermissions = [];

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->logPermissionChange($role, $permissionsBefore, "Seluruh hak akses dicabut dari peran '{$role->name}'.");

        $this->dispatch('notify', message: 'Semua hak akses peran berhasil dikosongkan.');
    }

    public function resetRoleToDefault(): void
    {
        abort_unless(Auth::user()->can('managePermissions', Role::class), 403);

        $role = $this->roles()->where('name', $this->selectedRoleName)->firstOrFail();
        $permissionsBefore = $this->permissionNamesOf($role);

        if (! isset(PermissionSeeder::DEFAULT_ROLE_PERMISSIONS[$role->name])) {
            $this->dispatch('notify', message: 'Peran kustom tidak memiliki perizinan bawaan (default).', type: 'error');

            return;
        }

        $default = PermissionSeeder::DEFAULT_ROLE_PERMISSIONS[$role->name];
        if ($default === ['*']) {
            $default = Permission::pluck('name')->all();
        }

        $role->syncPermissions($default);
        $this->rolePermissions = $default;

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->logPermissionChange($role, $permissionsBefore, "Hak akses peran '{$role->name}' dikembalikan ke setelan bawaan.");

        $this->dispatch('notify', message: "Hak akses peran '{$role->name}' dikembalikan ke bawaan.");
    }

    public function toggleMatrixPermission(int $roleId, string $permissionName): void
    {
        abort_unless(Auth::user()->can('managePermissions', Role::class), 403);

        $role = $this->roles()->findOrFail($roleId);
        $permissionsBefore = $this->permissionNamesOf($role);

        if ($role->hasPermissionTo($permissionName)) {
            $role->revokePermissionTo($permissionName);
            $action = 'dicabut dari';
        } else {
            $role->givePermissionTo($permissionName);
            $action = 'diberikan kepada';
        }

        if ($role->name === $this->selectedRoleName) {
            $this->loadSelectedRolePermissions();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->logPermissionChange($role, $permissionsBefore, "Matriks: Hak akses [{$permissionName}] {$action} peran '{$role->name}'.");

        $this->dispatch('notify', message: "Matriks perizinan untuk peran '{$role->name}' diperbarui.");
    }

    // Role CRUD Operations
    public function openCreateRoleModal(): void
    {
        abort_unless(Auth::user()->can('create', Role::class), 403);
        $this->newRoleName = '';
        $this->resetErrorBag();
        $this->showCreateRoleModal = true;
        $this->dispatch('open-modal', 'create-role');
    }

    public function createRole(): void
    {
        abort_unless(Auth::user()->can('create', Role::class), 403);

        $this->newRoleName = strtolower(trim($this->newRoleName));

        $this->validate([
            'newRoleName' => [
                'required',
                'string',
                'min:3',
                'max:40',
                'regex:/^[a-z0-9 ]+$/',
                TenantRule::unique('roles', 'name'),
            ],
        ], [
            'newRoleName.required' => 'Nama peran wajib diisi.',
            'newRoleName.min' => 'Nama peran minimal 3 karakter.',
            'newRoleName.max' => 'Nama peran maksimal 40 karakter.',
            'newRoleName.regex' => 'Nama peran hanya boleh berisi huruf kecil, angka, dan spasi.',
            'newRoleName.unique' => 'Nama peran ini sudah digunakan.',
        ]);

        $role = Role::create([
            'name' => $this->newRoleName,
            'guard_name' => 'web',
        ]);

        activity('roles')
            ->causedBy(Auth::user())
            ->performedOn($role)
            ->event('created')
            ->withChanges(['attributes' => ['name' => $role->name, 'guard_name' => $role->guard_name]])
            ->log("Peran baru '{$role->name}' berhasil dibuat.");

        $this->showCreateRoleModal = false;

        $this->dispatch('close-modal', 'create-role');
        $this->selectedRoleName = $role->name;
        $this->loadSelectedRolePermissions();

        $this->dispatch('notify', message: "Peran '{$role->name}' berhasil ditambahkan.");
    }

    public function openEditRoleModal(int $roleId): void
    {
        $role = $this->roles()->findOrFail($roleId);
        abort_unless(Auth::user()->can('update', $role), 403);

        if (in_array(strtolower($role->name), RolePolicy::PROTECTED_ROLES, true)) {
            $this->dispatch('notify', message: 'Peran sistem tidak dapat diubah namanya.', type: 'error');

            return;
        }

        $this->editingRoleId = $role->id;
        $this->editingRoleName = $role->name;
        $this->resetErrorBag();
        $this->showEditRoleModal = true;
        $this->dispatch('open-modal', 'edit-role');
    }

    public function updateRole(): void
    {
        $role = $this->roles()->findOrFail($this->editingRoleId);
        abort_unless(Auth::user()->can('update', $role), 403);

        if (in_array(strtolower($role->name), RolePolicy::PROTECTED_ROLES, true)) {
            $this->dispatch('notify', message: 'Peran sistem tidak dapat diubah namanya.', type: 'error');

            return;
        }

        $this->editingRoleName = strtolower(trim($this->editingRoleName));

        $this->validate([
            'editingRoleName' => [
                'required',
                'string',
                'min:3',
                'max:40',
                'regex:/^[a-z0-9 ]+$/',
                TenantRule::unique('roles', 'name')->ignore($role->id),
            ],
        ]);

        $oldName = $role->name;
        $role->name = $this->editingRoleName;
        $role->save();

        if ($this->selectedRoleName === $oldName) {
            $this->selectedRoleName = $role->name;
        }

        activity('roles')
            ->causedBy(Auth::user())
            ->performedOn($role)
            ->event('updated')
            ->withChanges(['old' => ['name' => $oldName], 'attributes' => ['name' => $role->name]])
            ->log("Nama peran '{$oldName}' diubah menjadi '{$role->name}'.");

        $this->showEditRoleModal = false;

        $this->dispatch('close-modal', 'edit-role');
        $this->dispatch('notify', message: "Nama peran berhasil diperbarui menjadi '{$role->name}'.");
    }

    public function confirmDeleteRole(int $roleId): void
    {
        $role = $this->roles()->findOrFail($roleId);
        abort_unless(Auth::user()->can('delete', $role), 403);

        if (in_array(strtolower($role->name), RolePolicy::PROTECTED_ROLES, true)) {
            $this->dispatch('notify', message: 'Peran sistem dilindungi dan tidak dapat dihapus demi keamanan operasional.', type: 'error');

            return;
        }

        if ($role->users()->count() > 0) {
            $count = $role->users()->count();
            $this->dispatch('notify', message: "Peran ini masih ditugaskan kepada {$count} pengguna. Pindahkan peran pengguna terlebih dahulu.", type: 'error');

            return;
        }

        $this->roleToDeleteId = $role->id;
        $this->showDeleteRoleModal = true;
        $this->dispatch('open-modal', 'delete-role');
    }

    public function deleteRole(): void
    {
        $role = $this->roles()->findOrFail($this->roleToDeleteId);
        abort_unless(Auth::user()->can('delete', $role), 403);

        if (in_array(strtolower($role->name), RolePolicy::PROTECTED_ROLES, true)) {
            $this->dispatch('notify', message: 'Peran sistem dilindungi dan tidak dapat dihapus.', type: 'error');

            return;
        }

        if ($role->users()->count() > 0) {
            $this->dispatch('notify', message: 'Peran masih memiliki pengguna aktif.', type: 'error');

            return;
        }

        $roleName = $role->name;
        $snapshot = [
            'id' => $role->id,
            'name' => $role->name,
            'guard_name' => $role->guard_name,
            'permissions' => $this->permissionNamesOf($role),
        ];
        $role->delete();

        activity('roles')
            ->causedBy(Auth::user())
            ->event('deleted')
            ->withChanges(['old' => $snapshot])
            ->log("Peran '{$roleName}' telah dihapus dari sistem.");

        $this->showDeleteRoleModal = false;

        $this->dispatch('close-modal', 'delete-role');
        $this->roleToDeleteId = null;

        if ($this->selectedRoleName === $roleName) {
            $this->selectedRoleName = 'superadmin';
            $this->loadSelectedRolePermissions();
        }

        $this->dispatch('notify', message: "Peran '{$roleName}' berhasil dihapus.");
    }

    // User Role Assignment
    public function openUserRolesModal(int $userId): void
    {
        abort_unless(Auth::user()->can('manageUserRoles', Role::class), 403);

        $user = User::findOrFail($userId);

        if ($user->hasRole('superadmin') && ! Auth::user()->can('assignSuperadmin', Role::class)) {
            $this->dispatch('notify', message: 'Peran akun Superadmin hanya bisa diubah oleh Superadmin.', type: 'error');

            return;
        }

        $this->editingUserId = $user->id;
        $this->editingUserRoles = $user->roles()->pluck('name')->all();
        $this->showUserRolesModal = true;
        $this->dispatch('open-modal', 'user-roles');
    }

    public function saveUserRoles(): void
    {
        abort_unless(Auth::user()->can('manageUserRoles', Role::class), 403);

        $user = User::findOrFail($this->editingUserId);
        $this->validate(
            ['editingUserRoles.*' => ['string', TenantRule::exists('roles', 'name')]],
            ['editingUserRoles.*.exists' => 'Peran yang dipilih tidak dikenal.'],
        );

        $touchesSuperadmin = $user->hasRole('superadmin') || in_array('superadmin', $this->editingUserRoles, true);

        if ($touchesSuperadmin && ! Auth::user()->can('assignSuperadmin', Role::class)) {
            $this->dispatch('notify', message: 'Peran Superadmin hanya bisa diberikan atau dicabut oleh Superadmin.', type: 'error');

            return;
        }

        // Security safeguard: Pastikan tidak menghapus akun superadmin terakhir
        if ($user->hasRole('superadmin') && ! in_array('superadmin', $this->editingUserRoles, true)) {
            $superadminCount = User::role('superadmin')->count();
            if ($superadminCount <= 1) {
                $this->dispatch('notify', message: 'Tidak dapat mencabut peran Superadmin terakhir demi keamanan sistem.', type: 'error');

                return;
            }
        }

        $rolesBefore = $user->roles()->pluck('name')->sort()->values()->all();
        $user->syncRoles($this->editingUserRoles);

        activity('roles')
            ->causedBy(Auth::user())
            ->performedOn($user)
            ->event('updated')
            ->withChanges([
                'old' => ['roles' => $rolesBefore],
                'attributes' => ['roles' => $user->roles()->pluck('name')->sort()->values()->all()],
            ])
            ->log("Peran pengguna '{$user->name}' diperbarui: ".implode(', ', $this->editingUserRoles));

        $this->showUserRolesModal = false;

        $this->dispatch('close-modal', 'user-roles');
        $this->dispatch('notify', message: "Penugasan peran untuk {$user->name} berhasil disimpan.");
    }

    public function openCreateUserModal(): void
    {
        abort_unless(Auth::user()->can('manageUserRoles', Role::class), 403);

        $this->reset('newUserName', 'newUserUsername', 'newUserEmail', 'newUserPhone', 'newUserPassword', 'newUserRoles');
        $this->resetValidation();
        $this->dispatch('open-modal', 'create-user');
    }

    public function closeCreateUserModal(): void
    {
        $this->reset('newUserPassword');
        $this->resetValidation();
    }

    public function createUser(): void
    {
        abort_unless(Auth::user()->can('manageUserRoles', Role::class), 403);

        $this->newUserUsername = strtolower(trim($this->newUserUsername));
        $this->newUserEmail = strtolower(trim($this->newUserEmail));
        $this->newUserPhone = filled($this->newUserPhone) ? User::normalizePhone($this->newUserPhone) : '';

        $validated = $this->validate([
            'newUserName' => ['required', 'string', 'max:255'],
            'newUserUsername' => User::usernameRules(),
            'newUserEmail' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'newUserPhone' => User::phoneRules(),
            'newUserPassword' => ['required', 'string', Password::defaults()],
            'newUserRoles' => ['required', 'array', 'min:1'],
            'newUserRoles.*' => ['string', TenantRule::exists('roles', 'name')],
        ], [
            ...collect(User::identityValidationMessages())->mapWithKeys(fn (string $message, string $key) => ['newUser'.ucfirst($key) => $message])->all(),
            'newUserRoles.required' => 'Pilih minimal satu peran supaya akun ini bisa dipakai.',
        ], [
            'newUserName' => 'nama',
            'newUserUsername' => 'username',
            'newUserEmail' => 'email',
            'newUserPhone' => 'nomor HP',
            'newUserPassword' => 'password',
        ]);

        if (in_array('superadmin', $validated['newUserRoles'], true) && ! Auth::user()->can('assignSuperadmin', Role::class)) {
            $this->addError('newUserRoles', 'Peran Superadmin hanya bisa diberikan oleh Superadmin.');

            return;
        }

        PlanLimits::ensureCanAdd('users', 'newUserName');

        $user = User::create([
            'name' => $validated['newUserName'],
            'username' => $validated['newUserUsername'],
            'email' => $validated['newUserEmail'],
            'phone' => $validated['newUserPhone'] ?: null,
            'password' => Hash::make($validated['newUserPassword']),
        ]);
        $user->syncRoles($validated['newUserRoles']);

        activity('roles')
            ->causedBy(Auth::user())
            ->performedOn($user)
            ->event('created')
            ->withProperties(['roles' => $validated['newUserRoles']])
            ->log("Akun pengguna '{$user->name}' dibuat dengan peran: ".implode(', ', $validated['newUserRoles']));

        $this->reset('newUserName', 'newUserUsername', 'newUserEmail', 'newUserPhone', 'newUserPassword', 'newUserRoles');
        $this->dispatch('close-modal', 'create-user');
        $this->dispatch('notify', message: "Akun {$user->name} dibuat. Sampaikan username dan password-nya ke pengguna.");
    }

    /**
     * Mengeluarkan akun dari aplikasi mobile di semua perangkat, mis. HP kasir hilang atau pegawai keluar.
     */
    public function revokeUserTokens(int $userId): void
    {
        abort_unless(Auth::user()->can('manageUserRoles', Role::class), 403);

        $user = User::findOrFail($userId);

        if ($user->hasRole('superadmin') && ! Auth::user()->can('assignSuperadmin', Role::class)) {
            $this->dispatch('notify', message: 'Perangkat akun Superadmin hanya bisa dikeluarkan oleh Superadmin.', type: 'error');

            return;
        }

        $count = $user->tokens()->delete();

        activity('roles')
            ->causedBy(Auth::user())
            ->performedOn($user)
            ->event('updated')
            ->withProperties(['revoked_tokens' => $count])
            ->log("Akun '{$user->name}' dikeluarkan dari {$count} perangkat mobile.");

        $this->dispatch('notify', message: "{$user->name} dikeluarkan dari {$count} perangkat mobile.");
    }

    /**
     * @return list<string>
     */
    private function permissionNamesOf(Role $role): array
    {
        return $role->permissions()->pluck('name')->sort()->values()->all();
    }

    /**
     * @param  list<string>  $before
     */
    private function logPermissionChange(Role $role, array $before, string $description): void
    {
        $after = $this->permissionNamesOf($role);

        activity('roles')
            ->causedBy(Auth::user())
            ->performedOn($role)
            ->event('updated')
            ->withChanges(['old' => ['permissions' => $before], 'attributes' => ['permissions' => $after]])
            ->withProperties([
                'granted' => array_values(array_diff($after, $before)),
                'revoked' => array_values(array_diff($before, $after)),
            ])
            ->log($description);
    }

    public function isProtectedRole(string $roleName): bool
    {
        return in_array(strtolower($roleName), RolePolicy::PROTECTED_ROLES, true);
    }

    /**
     * Model Role milik Spatie tidak diberi global scope tenant (cache izinnya memuat peran semua
     * toko sekaligus), jadi setiap query peran di halaman ini WAJIB lewat sini.
     *
     * @return Builder<Role>
     */
    private function roles(): Builder
    {
        return Role::query()->where('tenant_id', app(CurrentTenant::class)->id());
    }

    public function render()
    {
        $allRoles = $this->roles()->withCount(['users', 'permissions'])
            ->when($this->roleSearch, fn (Builder $q) => $q->where('name', 'like', "%{$this->roleSearch}%"))
            ->orderByRaw("CASE WHEN LOWER(name) = 'superadmin' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get();

        $selectedRole = $this->roles()->with('users')->where('name', $this->selectedRoleName)->first();

        $permissionGroups = PermissionSeeder::PERMISSION_GROUPS;

        if ($this->permissionSearch) {
            $search = mb_strtolower($this->permissionSearch);
            $filteredGroups = [];
            foreach ($permissionGroups as $module => $perms) {
                $matched = array_filter($perms, fn ($meta, $key) => str_contains(mb_strtolower($key), $search)
                    || str_contains(mb_strtolower($meta['label']), $search)
                    || str_contains(mb_strtolower($meta['description']), $search), ARRAY_FILTER_USE_BOTH);

                if (! empty($matched)) {
                    $filteredGroups[$module] = $matched;
                }
            }
            $permissionGroups = $filteredGroups;
        }

        $users = User::with('roles')
            ->withCount('tokens')
            ->when($this->userSearch, function (Builder $q) {
                $s = mb_strtolower($this->userSearch);
                $q->where(fn (Builder $sq) => $sq->where('name', 'like', "%{$s}%")
                    ->orWhere('username', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%"));
            })
            ->when($this->userRoleFilter, function (Builder $q) {
                $q->whereHas('roles', fn (Builder $rq) => $rq->where('name', $this->userRoleFilter));
            })
            ->orderBy('name')
            ->paginate(12);

        $editingUser = $this->editingUserId ? User::find($this->editingUserId) : null;

        return view('livewire.settings.roles-and-permissions', [
            'allRoles' => $allRoles,
            'selectedRole' => $selectedRole,
            'permissionGroups' => $permissionGroups,
            'allPermissionsList' => Permission::all(),
            'users' => $users,
            'editingUser' => $editingUser,
            'totalPermissionsCount' => Permission::count(),
            'totalUsersCount' => User::count(),
            'availableTabs' => $this->availableTabs(),
        ]);
    }
}
