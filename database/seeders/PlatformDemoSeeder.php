<?php

namespace Database\Seeders;

use App\Enums\StoreType;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSubscriptionLog;
use App\Models\User;
use App\Services\Pos\SaleService;
use App\Services\Pos\ShiftService;
use App\Services\StorePresetApplier;
use App\Services\TenantProvisioner;
use App\Support\CurrentTenant;
use App\Support\PosSettings;
use App\Support\SaasPlans;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Toko-toko contoh dengan kondisi langganan berbeda supaya panel Platform punya isi saat demo:
 * uji coba aktif, berlangganan, uji coba habis, dinonaktifkan, dan toko baru yang belum
 * menyelesaikan persiapan toko. Pemilik tiap toko login dengan password "password".
 */
class PlatformDemoSeeder extends Seeder
{
    /**
     * @var list<array{name: string, owner: string, type: ?StoreType, plan: string, ends_in_days: ?int, status: string, sales_days: int}>
     */
    private const TENANTS = [
        ['name' => 'Kopi Senja', 'owner' => 'kafe', 'type' => StoreType::Cafe, 'plan' => Tenant::PLAN_TRIAL, 'ends_in_days' => 5, 'status' => Tenant::STATUS_ACTIVE, 'sales_days' => 5],
        ['name' => 'Warung Bu Sri', 'owner' => 'warung', 'type' => StoreType::Warung, 'plan' => Tenant::PLAN_PRO, 'ends_in_days' => 40, 'status' => Tenant::STATUS_ACTIVE, 'sales_days' => 4],
        ['name' => 'Toko Elektronik Makmur', 'owner' => 'elektronik', 'type' => StoreType::PhoneCounter, 'plan' => Tenant::PLAN_LIFETIME, 'ends_in_days' => null, 'status' => Tenant::STATUS_ACTIVE, 'sales_days' => 6],
        ['name' => 'Minimarket Sejahtera', 'owner' => 'minimarket', 'type' => StoreType::Minimarket, 'plan' => Tenant::PLAN_TRIAL, 'ends_in_days' => -3, 'status' => Tenant::STATUS_ACTIVE, 'sales_days' => 0],
        ['name' => 'Butik Anggun', 'owner' => 'fashion', 'type' => StoreType::Fashion, 'plan' => Tenant::PLAN_PRO, 'ends_in_days' => 20, 'status' => Tenant::STATUS_SUSPENDED, 'sales_days' => 0],
        ['name' => 'Roti Pagi', 'owner' => 'bakery', 'type' => null, 'plan' => Tenant::PLAN_FREE, 'ends_in_days' => null, 'status' => Tenant::STATUS_ACTIVE, 'sales_days' => 0],
    ];

    public function run(TenantProvisioner $provisioner, StorePresetApplier $presets, CurrentTenant $currentTenant): void
    {
        // Pastikan konfigurasi paket SaaS termasuk Lifetime tersimpan di database setting
        SaasPlans::save(SaasPlans::all());

        $platformAdmin = User::withoutGlobalScopes()->where('email', 'platform@demo.com')->first()
            ?? User::withoutGlobalScopes()->where('is_platform_admin', true)->first();

        foreach (self::TENANTS as $index => $demo) {
            User::withoutGlobalScopes()
                ->where('email', "{$demo['owner']}@example.test")
                ->update(['email' => "{$demo['owner']}@demo.com"]);

            if (Tenant::query()->where('slug', Str::slug($demo['name']))->exists()) {
                continue;
            }

            ['tenant' => $tenant, 'owner' => $owner] = $provisioner->provision($demo['name'], [
                'name' => 'Pemilik '.$demo['name'],
                'username' => $demo['owner'],
                'email' => "{$demo['owner']}@demo.com",
                'password' => 'password',
            ], $demo['plan']);

            $tenant->forceFill(['created_at' => now()->subDays(40 - $index * 7)])->save();

            if ($demo['type']) {
                $presets->apply($tenant, $demo['type']);
            }

            $endsAt = $demo['ends_in_days'] !== null ? now()->addDays($demo['ends_in_days'])->endOfDay() : null;
            $tenant->forceFill([
                'status' => $demo['status'],
                ...($demo['plan'] === Tenant::PLAN_TRIAL ? ['trial_ends_at' => $endsAt] : ['subscription_ends_at' => $endsAt]),
            ])->save();

            if ($demo['plan'] !== Tenant::PLAN_TRIAL) {
                $isLifetime = $demo['plan'] === Tenant::PLAN_LIFETIME;
                TenantSubscriptionLog::query()->create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $platformAdmin?->id,
                    'action' => TenantSubscriptionLog::ACTION_UPDATE,
                    'from_plan' => Tenant::PLAN_TRIAL,
                    'to_plan' => $demo['plan'],
                    'from_status' => Tenant::STATUS_ACTIVE,
                    'to_status' => $demo['status'],
                    'to_ends_at' => $endsAt,
                    'amount' => $isLifetime ? 499000 : ($demo['plan'] === Tenant::PLAN_FREE ? 0 : 199000),
                    'note' => $isLifetime ? 'Pembelian Paket Lifetime (Permanen)' : 'Aktivasi Paket '.SaasPlans::label($demo['plan']),
                ]);
            }

            if ($demo['sales_days'] > 0) {
                $currentTenant->run($tenant, fn () => $this->seedSales($owner, $demo['sales_days']));
            }
        }
    }

    /**
     * Beberapa transaksi tunai per hari dari produk yang tidak melacak stok atau boleh minus,
     * cukup untuk mengisi grafik dan daftar "toko paling aktif".
     */
    private function seedSales(User $cashier, int $days): void
    {
        $shifts = app(ShiftService::class);
        $sales = app(SaleService::class);
        $products = Product::query()->where('is_active', true)
            ->when(! PosSettings::allowNegativeStock(), fn ($query) => $query->where('track_stock', false))
            ->get();

        if ($products->isEmpty()) {
            return;
        }

        mt_srand(crc32($cashier->email));

        foreach (range($days, 1) as $daysAgo) {
            $day = today()->subDays($daysAgo);
            Carbon::setTestNow($day->copy()->setTime(8, 0));
            $shift = $shifts->open($cashier, 100000);

            $count = mt_rand(4, 9);
            for ($i = 0; $i < $count; $i++) {
                Carbon::setTestNow($day->copy()->setTime(9, 0)->addMinutes(50 * $i + mt_rand(0, 30)));

                $items = $products->random(min($products->count(), mt_rand(1, 3)))->map(fn (Product $product) => [
                    'product_id' => $product->id,
                    'quantity' => mt_rand(1, 2),
                    'price' => $product->price,
                    'discount' => 0,
                ])->values()->all();

                // Dilebihkan untuk pajak toko (mis. PB1 kafe); kembaliannya dihitung kasir.
                $total = collect($items)->sum(fn (array $item) => $item['price'] * $item['quantity']) * 1.2;

                $sales->checkout($cashier, [
                    'client_uuid' => (string) Str::uuid(),
                    'items' => $items,
                    'payments' => [['method' => 'cash', 'amount' => (int) (ceil($total / 10000) * 10000)]],
                ]);
            }

            Carbon::setTestNow($day->copy()->setTime(21, 0));
            $shifts->close($shift, $shift->summary()['expected'], $cashier);
        }

        Carbon::setTestNow();
    }
}
