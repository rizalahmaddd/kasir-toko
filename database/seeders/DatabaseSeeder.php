<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
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
        $tenant = Tenant::query()->firstOrCreate(['slug' => 'toko-demo'], ['name' => 'Toko Demo', 'plan' => 'pro']);

        app(CurrentTenant::class)->run($tenant, function () {
            $this->call(RoleSeeder::class);
            $this->call(SettingsSeeder::class);

            $this->seedDemoUsers();

            $this->call(MasterDataSeeder::class);
            $this->call(PosDemoSeeder::class);
        });

        $this->seedPlatformAdmin();
    }

    /**
     * Pengelola platform SaaS: tidak terikat toko mana pun, hanya membuka panel Platform.
     */
    protected function seedPlatformAdmin(): void
    {
        app(CurrentTenant::class)->run(null, fn () => User::query()->firstOrCreate(
            ['email' => 'platform@example.test'],
            ['name' => 'Platform Admin', 'username' => 'platform', 'password' => Hash::make('password')],
        )->forceFill(['email_verified_at' => now(), 'is_platform_admin' => true])->save());
    }

    /**
     * Satu user demo per peran supaya tiap peran bisa langsung dicoba login selama pengembangan.
     * Password sama untuk semua: "password". Jangan jalankan seeder ini di production.
     */
    protected function seedDemoUsers(): void
    {
        $demoUsers = [
            ['name' => 'Superadmin Demo', 'email' => 'superadmin@example.test', 'role' => 'superadmin'],
            ['name' => 'Admin Demo', 'email' => 'admin@example.test', 'role' => 'admin'],
            ['name' => 'Kasir Demo', 'email' => 'kasir@example.test', 'role' => 'kasir'],
            ['name' => 'Staff Demo', 'email' => 'staff@example.test', 'role' => 'staff'],
        ];

        foreach ($demoUsers as $demoUser) {
            User::firstOrCreate(
                ['email' => $demoUser['email']],
                [
                    'name' => $demoUser['name'],
                    'username' => Str::before($demoUser['email'], '@'),
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            )->assignRole($demoUser['role']);
        }
    }
}
