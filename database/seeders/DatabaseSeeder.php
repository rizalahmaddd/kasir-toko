<?php

namespace Database\Seeders;

use App\Enums\StoreType;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\CurrentTenant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => 'toko-demo'], ['name' => 'Toko Demo', 'plan' => 'pro', 'store_type' => StoreType::Warung, 'onboarded_at' => now()]);

        app(CurrentTenant::class)->run($tenant, function () use ($tenant) {
            Outlet::query()->firstOrCreate(['code' => Outlet::DEFAULT_CODE], ['name' => $tenant->name, 'is_primary' => true]);
            app(CurrentOutlet::class)->flush();

            $this->call(RoleSeeder::class);
            $this->call(SettingsSeeder::class);

            $this->seedDemoUsers();

            $this->call(MasterDataSeeder::class);
            $this->call(PosDemoSeeder::class);
        });

        $this->seedPlatformAdmin();
        $this->call(PlatformDemoSeeder::class);
    }

    /**
     * Pengelola platform SaaS: tidak terikat toko mana pun, hanya membuka panel Platform.
     */
    protected function seedPlatformAdmin(): void
    {
        app(CurrentTenant::class)->run(null, function () {
            if ($legacy = User::query()->where('email', 'platform@example.test')->first()) {
                $legacy->update(['email' => 'platform@demo.com']);
            }

            User::query()->firstOrCreate(
                ['email' => 'platform@demo.com'],
                ['name' => 'Platform Admin', 'username' => 'platform', 'password' => Hash::make('password')],
            )->forceFill(['email_verified_at' => now(), 'is_platform_admin' => true])->save();
        });
    }

    /**
     * Satu user demo per peran supaya tiap peran bisa langsung dicoba login selama pengembangan.
     * Password sama untuk semua: "password". Jangan jalankan seeder ini di production.
     */
    protected function seedDemoUsers(): void
    {
        $demoUsers = [
            ['name' => 'Owner Demo', 'email' => 'owner@demo.com', 'role' => 'superadmin'],
            ['name' => 'Admin Demo', 'email' => 'admin@demo.com', 'role' => 'admin'],
            ['name' => 'Kasir Demo', 'email' => 'kasir@demo.com', 'role' => 'kasir'],
            ['name' => 'Staff Demo', 'email' => 'staff@demo.com', 'role' => 'staff'],
        ];

        $legacyMap = [
            'superadmin@example.test' => ['email' => 'owner@demo.com', 'username' => 'owner', 'name' => 'Owner Demo'],
            'admin@example.test' => ['email' => 'admin@demo.com', 'username' => 'admin', 'name' => 'Admin Demo'],
            'kasir@example.test' => ['email' => 'kasir@demo.com', 'username' => 'kasir', 'name' => 'Kasir Demo'],
            'staff@example.test' => ['email' => 'staff@demo.com', 'username' => 'staff', 'name' => 'Staff Demo'],
        ];

        foreach ($legacyMap as $oldEmail => $newAttributes) {
            if ($legacyUser = User::where('email', $oldEmail)->first()) {
                $legacyUser->update($newAttributes);
            }
        }

        foreach ($demoUsers as $demoUser) {
            $user = User::firstOrCreate(
                ['email' => $demoUser['email']],
                [
                    'name' => $demoUser['name'],
                    'username' => Str::before($demoUser['email'], '@'),
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );

            if (! $user->hasRole($demoUser['role'])) {
                $user->assignRole($demoUser['role']);
            }

            if (in_array($demoUser['role'], ['superadmin', 'admin'], true) && ! $user->all_outlets) {
                $user->forceFill(['all_outlets' => true])->save();
            }
        }
    }
}
