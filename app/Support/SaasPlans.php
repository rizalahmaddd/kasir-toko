<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Paket langganan yang diatur admin platform dari panel. Selama belum pernah disimpan, paket
 * dibaca dari config/saas.php. Kunci paket tersimpan di tenants.plan, jadi kunci tidak pernah
 * diganti; paket hanya boleh dihapus kalau tidak ada toko yang memakainya.
 */
class SaasPlans
{
    public const SETTING_KEY = 'saas.plans';

    public const DEFAULT_PLANS = [
        'trial' => ['label' => 'Uji Coba Pro', 'price' => null, 'max_users' => null, 'max_products' => null, 'max_outlets' => 1],
        'free' => ['label' => 'Gratis', 'price' => 0, 'max_users' => null, 'max_products' => null, 'max_outlets' => 1],
        'pro' => ['label' => 'Pro', 'price' => 20000, 'yearly_price' => 199000, 'max_users' => null, 'max_products' => null, 'max_outlets' => 5],
        'lifetime' => ['label' => 'Lifetime (Permanen)', 'price' => 499000, 'yearly_price' => null, 'max_users' => null, 'max_products' => null, 'max_outlets' => 5],
    ];

    /**
     * @return array<string, array{label: string, price: ?int, max_users: ?int, max_products: ?int, max_outlets: int}>
     */
    public static function all(): array
    {
        $stored = json_decode((string) Setting::platform(self::SETTING_KEY), true);
        $sourcePlans = config('saas.plans', []);
        $plans = is_array($stored) && $stored !== [] ? $stored : ($sourcePlans ?: self::DEFAULT_PLANS);

        // Pastikan paket bawaan (terutama free dan lifetime) selalu ada meski DB atau config server versi lama
        $combinedDefaults = array_merge(self::DEFAULT_PLANS, $sourcePlans);
        foreach ($combinedDefaults as $configKey => $configPlan) {
            if (! isset($plans[$configKey])) {
                $plans[$configKey] = $configPlan;
            }
        }

        return collect($plans)
            ->map(fn (array $plan, string $key): array => [
                'label' => (string) ($plan['label'] ?? Str::title($key)),
                'price' => self::nullableInt($plan['price'] ?? null),
                'monthly_discount' => self::nullableInt($plan['monthly_discount'] ?? null),
                'yearly_price' => self::nullableInt($plan['yearly_price'] ?? null),
                'yearly_discount' => self::nullableInt($plan['yearly_discount'] ?? null),
                'max_users' => self::nullableInt($plan['max_users'] ?? null),
                'max_products' => self::nullableInt($plan['max_products'] ?? null),
                'max_outlets' => max(1, self::nullableInt($plan['max_outlets'] ?? null) ?? self::DEFAULT_PLANS[$key]['max_outlets'] ?? 1),
            ])
            ->all();
    }

    public static function effectiveMonthlyPrice(array|string $plan): ?int
    {
        $data = is_string($plan) ? self::find($plan) : $plan;
        if (! $data || $data['price'] === null) {
            return null;
        }

        $discount = (int) ($data['monthly_discount'] ?? 0);

        return max(0, $data['price'] - $discount);
    }

    public static function effectiveYearlyPrice(array|string $plan): ?int
    {
        $data = is_string($plan) ? self::find($plan) : $plan;
        if (! $data || ($data['yearly_price'] ?? null) === null) {
            return null;
        }

        $discount = (int) ($data['yearly_discount'] ?? 0);

        return max(0, $data['yearly_price'] - $discount);
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_map(fn (array $plan) => $plan['label'], self::all());
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function label(?string $key): string
    {
        return self::all()[$key]['label'] ?? Str::title((string) $key);
    }

    /**
     * @return array{label: string, price: ?int, max_users: ?int, max_products: ?int, max_outlets: int}|null
     */
    public static function find(?string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * @param  array<string, array{label: string, price: ?int, max_users: ?int, max_products: ?int, max_outlets?: int}>  $plans
     */
    public static function save(array $plans): void
    {
        app(CurrentTenant::class)->run(null, fn () => Setting::put(self::SETTING_KEY, json_encode($plans)));
    }

    public static function isUsed(string $key): bool
    {
        return $key === Tenant::PLAN_TRIAL || Tenant::query()->where('plan', $key)->exists();
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
