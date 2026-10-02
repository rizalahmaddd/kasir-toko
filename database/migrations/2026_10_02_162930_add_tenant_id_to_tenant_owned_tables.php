<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Tables whose rows always belong to a shop. users, settings and activity_log stay nullable:
     * a NULL there means platform level (future platform admins, default branding, failed logins).
     */
    private const REQUIRED = [
        'customers', 'categories', 'products', 'cash_shifts', 'cash_movements', 'sales', 'sale_items',
        'sale_payments', 'stock_movements', 'held_orders',
    ];

    private const NULLABLE = ['users', 'settings', 'activity_log'];

    /**
     * @var array<string, list<string>>
     */
    private const PER_TENANT_UNIQUE = [
        'customers' => ['code'],
        'categories' => ['name'],
        'products' => ['sku', 'barcode'],
        'cash_shifts' => ['number'],
        'sales' => ['number'],
        'settings' => ['key'],
    ];

    public function up(): void
    {
        foreach ([...self::REQUIRED, ...self::NULLABLE] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->after('id')->constrained();
            });
        }

        $this->assignExistingRowsToDefaultTenant();

        foreach (self::REQUIRED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
            });
        }

        foreach (self::PER_TENANT_UNIQUE as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column) {
                    $table->dropUnique([$column]);
                    $table->unique(['tenant_id', $column]);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::PER_TENANT_UNIQUE as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column) {
                    $table->dropUnique(['tenant_id', $column]);
                    $table->unique([$column]);
                }
            });
        }

        foreach ([...self::REQUIRED, ...self::NULLABLE] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('tenant_id');
            });
        }
    }

    /**
     * An existing single-shop install becomes the first tenant, named after its company profile,
     * on the top plan with no end date so the running shop is never locked out by the upgrade.
     */
    private function assignExistingRowsToDefaultTenant(): void
    {
        $hasData = collect([...self::REQUIRED, ...self::NULLABLE])->contains(fn (string $name) => DB::table($name)->exists());

        if (! $hasData) {
            return;
        }

        $profile = DB::table('settings')->whereIn('key', ['company_name', 'app_name'])->pluck('value', 'key');
        $name = $profile['company_name'] ?? $profile['app_name'] ?? 'Toko Utama';

        $tenantId = DB::table('tenants')->insertGetId([
            'name' => $name,
            'slug' => Str::slug($name) ?: 'toko-utama',
            'status' => 'active',
            'plan' => 'pro',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([...self::REQUIRED, ...self::NULLABLE] as $name) {
            DB::table($name)->update(['tenant_id' => $tenantId]);
        }
    }
};
