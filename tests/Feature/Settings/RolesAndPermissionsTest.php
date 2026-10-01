<?php

use App\Livewire\Settings\RolesAndPermissions;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
});

test('superadmin can bypass gate and access company profile settings', function () {
    $superadmin = actingAsSuperAdmin();

    expect($superadmin->isSuperAdmin())->toBeTrue()
        ->and($superadmin->can('update', Setting::class))->toBeTrue();

    $this->get(route('settings.company-profile'))->assertOk();
});

test('superadmin can open every page regardless of permissions', function () {
    actingAsSuperAdmin();

    $this->get(route('reports.activity-log'))->assertOk();
    $this->get(route('master-data.customers'))->assertOk();
    $this->get(route('settings.backups'))->assertOk();
});

test('guests cannot view roles and permissions management', function () {
    $this->get(route('settings.roles-and-permissions'))->assertRedirect('/login');
});

test('unauthorized users cannot access roles and permissions management', function () {
    actingAsRole('staff');

    $this->get(route('settings.roles-and-permissions'))->assertForbidden();
});

test('superadmin and holders of the roles permission can view roles and permissions page', function () {
    actingAsSuperAdmin();
    $this->get(route('settings.roles-and-permissions'))->assertOk()
        ->assertSee('Pengaturan Peran & Perizinan Sistem');

    $user = User::factory()->create();
    $user->givePermissionTo('roles.manage');
    $this->actingAs($user)->get(route('settings.roles-and-permissions'))->assertOk();
});

test('superadmin can create a new custom role', function () {
    actingAsSuperAdmin();

    Livewire::test(RolesAndPermissions::class)
        ->set('newRoleName', 'supervisor')
        ->call('createRole')
        ->assertHasNoErrors();

    expect(Role::where('name', 'supervisor')->exists())->toBeTrue();

    $log = Activity::where('log_name', 'roles')->latest()->first();
    expect($log)->not->toBeNull()
        ->and($log->description)->toContain('supervisor');
});

test('role name must be valid and unique', function () {
    actingAsSuperAdmin();

    Livewire::test(RolesAndPermissions::class)
        ->set('newRoleName', 'staff') // already exists
        ->call('createRole')
        ->assertHasErrors(['newRoleName' => 'unique']);
});

test('protected system roles cannot be renamed or deleted', function () {
    actingAsSuperAdmin();

    $superadminRole = Role::findByName('superadmin');
    $adminRole = Role::findByName('admin');

    // Cannot delete superadmin
    Livewire::test(RolesAndPermissions::class)
        ->call('confirmDeleteRole', $superadminRole->id)
        ->assertDispatched('notify');

    expect(Role::where('name', 'superadmin')->exists())->toBeTrue();

    // Cannot delete admin
    Livewire::test(RolesAndPermissions::class)
        ->call('confirmDeleteRole', $adminRole->id)
        ->assertDispatched('notify');

    expect(Role::where('name', 'admin')->exists())->toBeTrue();

    // Cannot edit superadmin or admin
    Livewire::test(RolesAndPermissions::class)
        ->call('openEditRoleModal', $superadminRole->id)
        ->assertDispatched('notify');
});

test('custom role can be renamed and deleted if no users assigned', function () {
    actingAsSuperAdmin();

    $role = Role::create(['name' => 'staf qc', 'guard_name' => 'web']);

    // Rename
    Livewire::test(RolesAndPermissions::class)
        ->call('openEditRoleModal', $role->id)
        ->set('editingRoleName', 'senior qc')
        ->call('updateRole')
        ->assertHasNoErrors();

    expect($role->fresh()->name)->toBe('senior qc');

    // Delete
    Livewire::test(RolesAndPermissions::class)
        ->call('confirmDeleteRole', $role->id)
        ->call('deleteRole')
        ->assertHasNoErrors();

    expect(Role::where('name', 'senior qc')->exists())->toBeFalse();
});

test('role cannot be deleted if assigned to users', function () {
    actingAsSuperAdmin();

    $role = Role::create(['name' => 'helper logistik', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    Livewire::test(RolesAndPermissions::class)
        ->call('confirmDeleteRole', $role->id)
        ->assertDispatched('notify');

    expect(Role::where('name', 'helper logistik')->exists())->toBeTrue();
});

test('superadmin can toggle permissions for a role', function () {
    actingAsSuperAdmin();

    $role = Role::findByName('staff');
    $permission = 'master-data.manage';

    expect($role->hasPermissionTo($permission))->toBeFalse();

    Livewire::test(RolesAndPermissions::class)
        ->call('selectRole', 'staff')
        ->call('toggleRolePermission', $permission);

    expect($role->fresh()->hasPermissionTo($permission))->toBeTrue();

    Livewire::test(RolesAndPermissions::class)
        ->call('selectRole', 'staff')
        ->call('toggleRolePermission', $permission);

    expect($role->fresh()->hasPermissionTo($permission))->toBeFalse();
});

test('superadmin can select and clear all permissions in a module', function () {
    actingAsSuperAdmin();

    $role = Role::create(['name' => 'tamu', 'guard_name' => 'web']);

    Livewire::test(RolesAndPermissions::class)
        ->call('selectRole', 'tamu')
        ->call('selectAllInModule', 'Produk & Stok');

    expect($role->fresh()->hasPermissionTo('master-data.view'))->toBeTrue()
        ->and($role->fresh()->hasPermissionTo('master-data.manage'))->toBeTrue();

    Livewire::test(RolesAndPermissions::class)
        ->call('selectRole', 'tamu')
        ->call('clearAllInModule', 'Produk & Stok');

    expect($role->fresh()->hasPermissionTo('master-data.view'))->toBeFalse();
});

test('superadmin can assign and update user roles', function () {
    actingAsSuperAdmin();

    $user = User::factory()->create();
    $user->assignRole('staff');

    expect($user->hasRole('staff'))->toBeTrue()
        ->and($user->hasRole('admin'))->toBeFalse();

    Livewire::test(RolesAndPermissions::class)
        ->call('openUserRolesModal', $user->id)
        ->set('editingUserRoles', ['staff', 'admin'])
        ->call('saveUserRoles');

    expect($user->fresh()->hasRole('admin'))->toBeTrue()
        ->and($user->fresh()->hasRole('staff'))->toBeTrue();
});

test('superadmin safeguard prevents removing superadmin role from the only superadmin', function () {
    $superadmin = actingAsSuperAdmin();

    // Ensure there is only 1 superadmin user
    User::role('superadmin')->where('id', '!=', $superadmin->id)->each(fn ($u) => $u->removeRole('superadmin'));

    expect(User::role('superadmin')->count())->toBe(1);

    Livewire::test(RolesAndPermissions::class)
        ->call('openUserRolesModal', $superadmin->id)
        ->set('editingUserRoles', ['admin']) // trying to remove superadmin
        ->call('saveUserRoles')
        ->assertDispatched('notify');

    expect($superadmin->fresh()->hasRole('superadmin'))->toBeTrue();
});

test('role modals are opened and closed through browser modal events', function () {
    actingAsSuperAdmin();

    $user = User::factory()->create();

    Livewire::test(RolesAndPermissions::class)
        ->call('openCreateRoleModal')
        ->assertDispatched('open-modal', 'create-role')
        ->set('newRoleName', 'staff qc')
        ->call('createRole')
        ->assertDispatched('close-modal', 'create-role')
        ->call('openUserRolesModal', $user->id)
        ->assertDispatched('open-modal', 'user-roles')
        ->assertSee($user->name);
});

test('user managers without the roles permission only get the users tab', function () {
    actingAsRole('admin');

    $this->get(route('settings.roles-and-permissions'))->assertOk();

    Livewire::test(RolesAndPermissions::class)
        ->assertSet('tab', 'users')
        ->set('tab', 'matrix')
        ->assertSet('tab', 'users');
});

test('user managers cannot hand out or take away the superadmin role', function () {
    $admin = actingAsRole('admin');
    $superadmin = User::factory()->create()->assignRole('superadmin');

    Livewire::test(RolesAndPermissions::class)
        ->set('editingUserId', $admin->id)
        ->set('editingUserRoles', ['admin', 'superadmin'])
        ->call('saveUserRoles')
        ->assertDispatched('notify', type: 'error');

    Livewire::test(RolesAndPermissions::class)
        ->set('editingUserId', $superadmin->id)
        ->set('editingUserRoles', ['staff'])
        ->call('saveUserRoles');

    expect($admin->fresh()->hasRole('superadmin'))->toBeFalse()
        ->and($superadmin->fresh()->hasRole('superadmin'))->toBeTrue();
});

test('user managers create accounts that can log in with the chosen role', function () {
    actingAsRole('admin');

    Livewire::test(RolesAndPermissions::class)
        ->call('openCreateUserModal')
        ->set('newUserName', 'Sari Kasir')
        ->set('newUserUsername', 'Sari.Kasir')
        ->set('newUserEmail', 'sari@example.test')
        ->set('newUserPhone', '+62 812-1111-2222')
        ->set('newUserPassword', 'rahasia123')
        ->set('newUserRoles', ['kasir'])
        ->call('createUser')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal', 'create-user');

    $user = User::where('username', 'sari.kasir')->sole();
    expect($user->phone)->toBe('081211112222')
        ->and($user->hasRole('kasir'))->toBeTrue()
        ->and(Hash::check('rahasia123', $user->password))->toBeTrue();

    Livewire::test(RolesAndPermissions::class)
        ->set('newUserName', 'Tanpa Peran')
        ->set('newUserUsername', 'tanpa')
        ->set('newUserEmail', 'tanpa@example.test')
        ->set('newUserPassword', 'rahasia123')
        ->set('newUserRoles', ['superadmin'])
        ->call('createUser')
        ->assertHasErrors('newUserRoles');

    expect(User::where('username', 'tanpa')->exists())->toBeFalse();
});

test('user managers sign a user out of every mobile device', function () {
    actingAsRole('admin');
    $cashier = User::factory()->create()->assignRole('kasir');
    $cashier->createToken('hp-kasir');
    $cashier->createToken('tablet');

    Livewire::test(RolesAndPermissions::class)
        ->call('revokeUserTokens', $cashier->id)
        ->assertDispatched('notify');

    expect($cashier->tokens()->count())->toBe(0);
});

test('a new username must start with a letter so it is never mistaken for a phone number', function () {
    actingAsRole('admin');

    Livewire::test(RolesAndPermissions::class)
        ->set('newUserName', 'Test User')
        ->set('newUserUsername', '0812345')
        ->set('newUserEmail', 'test@example.com')
        ->set('newUserPassword', 'rahasia123')
        ->set('newUserRoles', ['kasir'])
        ->call('createUser')
        ->assertHasErrors(['newUserUsername' => 'regex']);
});
