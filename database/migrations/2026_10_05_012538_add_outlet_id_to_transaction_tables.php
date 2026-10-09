<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TABLES = ['sales', 'sale_payments', 'cash_shifts', 'cash_movements', 'stock_movements', 'held_orders'];

    private const CHUNK = 5000;

    private const PLAN_OUTLET_LIMITS = ['free' => 1, 'trial' => 1, 'pro' => 5, 'lifetime' => 5];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('outlet_id')->nullable()->constrained('outlets')->restrictOnDelete();
            });
        }

        Schema::table('sales', fn (Blueprint $table) => $table->index(['outlet_id', 'status', 'sold_at']));
        Schema::table('cash_shifts', fn (Blueprint $table) => $table->index(['outlet_id', 'closed_at']));
        Schema::table('stock_movements', fn (Blueprint $table) => $table->index(['outlet_id', 'product_id', 'created_at']));

        // Tidak ada tenant aktif saat migrasi, jadi semua query memakai DB::table dengan where tenant_id manual.
        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            $outletId = $this->ensurePrimaryOutlet((int) $tenantId);

            foreach (self::TABLES as $name) {
                $this->backfillOutlet($name, (int) $tenantId, $outletId);
            }

            $this->seedProductStocks((int) $tenantId, $outletId);
            $this->assignUsers((int) $tenantId, $outletId);
        }

        $this->addOutletLimitToStoredPlans();
    }

    public function down(): void
    {
        // Foreign keys go first: MySQL refuses to drop the composite indexes while an FK still relies on them.
        foreach (self::TABLES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropForeign(['outlet_id']));
        }

        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropIndex(['outlet_id', 'product_id', 'created_at']));
        Schema::table('cash_shifts', fn (Blueprint $table) => $table->dropIndex(['outlet_id', 'closed_at']));
        Schema::table('sales', fn (Blueprint $table) => $table->dropIndex(['outlet_id', 'status', 'sold_at']));

        foreach (self::TABLES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('outlet_id'));
        }
    }

    private function ensurePrimaryOutlet(int $tenantId): int
    {
        $existing = DB::table('outlets')->where('tenant_id', $tenantId)->where('is_primary', true)->value('id');

        if ($existing) {
            return (int) $existing;
        }

        $settings = DB::table('settings')->where('tenant_id', $tenantId)
            ->whereIn('key', ['company_name', 'company_address', 'company_phone'])->pluck('value', 'key');
        $name = $settings['company_name'] ?? DB::table('tenants')->where('id', $tenantId)->value('name') ?? 'Toko Utama';

        return (int) DB::table('outlets')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => Str::limit((string) $name, 100, ''),
            'code' => 'PST',
            'address' => filled($settings['company_address'] ?? null) ? Str::limit($settings['company_address'], 255, '') : null,
            'phone' => filled($settings['company_phone'] ?? null) ? Str::limit($settings['company_phone'], 30, '') : null,
            'is_primary' => true,
            'is_active' => true,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Dibagi per rentang id supaya tabel besar tidak dikunci satu UPDATE panjang.
     */
    private function backfillOutlet(string $table, int $tenantId, int $outletId): void
    {
        $bounds = DB::table($table)->where('tenant_id', $tenantId)->whereNull('outlet_id')
            ->selectRaw('MIN(id) as first_id, MAX(id) as last_id')->first();

        if (! $bounds || $bounds->first_id === null) {
            return;
        }

        for ($from = (int) $bounds->first_id; $from <= (int) $bounds->last_id; $from += self::CHUNK) {
            DB::table($table)
                ->where('tenant_id', $tenantId)
                ->whereNull('outlet_id')
                ->whereBetween('id', [$from, $from + self::CHUNK - 1])
                ->update(['outlet_id' => $outletId]);
        }
    }

    /**
     * Produk yang sudah dihapus ikut disalin supaya pembatalan transaksi lama tetap bisa mengembalikan stok.
     */
    private function seedProductStocks(int $tenantId, int $outletId): void
    {
        DB::table('product_stocks')->insertUsing(
            ['tenant_id', 'product_id', 'outlet_id', 'stock', 'min_stock', 'created_at', 'updated_at'],
            DB::table('products')
                ->where('tenant_id', $tenantId)
                ->whereNotExists(fn ($query) => $query->from('product_stocks')
                    ->whereColumn('product_stocks.product_id', 'products.id')
                    ->where('product_stocks.outlet_id', $outletId))
                ->selectRaw('tenant_id, id, ?, stock, NULL, ?, ?', [$outletId, now(), now()]),
        );
    }

    private function assignUsers(int $tenantId, int $outletId): void
    {
        $adminIds = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.tenant_id', $tenantId)
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->whereIn('roles.name', ['superadmin', 'admin'])
            ->pluck('model_has_roles.model_id')
            ->all();

        DB::table('users')->where('tenant_id', $tenantId)->whereIn('id', $adminIds)->update(['all_outlets' => true]);
        DB::table('users')->where('tenant_id', $tenantId)->whereNull('default_outlet_id')->update(['default_outlet_id' => $outletId]);

        $limitedIds = DB::table('users')->where('tenant_id', $tenantId)->where('all_outlets', false)->pluck('id');

        foreach ($limitedIds->chunk(500) as $chunk) {
            DB::table('outlet_user')->insertOrIgnore($chunk->map(fn ($userId) => [
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'user_id' => $userId,
            ])->all());
        }
    }

    /**
     * Paket yang sudah tersimpan belum punya max_outlets; tanpa ini toko Free dianggap tanpa batas outlet.
     */
    private function addOutletLimitToStoredPlans(): void
    {
        $row = DB::table('settings')->whereNull('tenant_id')->where('key', 'saas.plans')->first();
        $plans = $row ? json_decode((string) $row->value, true) : null;

        if (! is_array($plans)) {
            return;
        }

        foreach ($plans as $key => $plan) {
            if (is_array($plan) && ! isset($plan['max_outlets'])) {
                $plans[$key]['max_outlets'] = self::PLAN_OUTLET_LIMITS[$key] ?? 1;
            }
        }

        DB::table('settings')->where('id', $row->id)->update(['value' => json_encode($plans)]);
        Cache::forget('settings.platform');
    }
};
