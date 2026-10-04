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

    /**
     * @return array<string, array{label: string, price: ?int, max_users: ?int, max_products: ?int}>
     */
    public static function all(): array
    {
        $stored = json_decode((string) Setting::platform(self::SETTING_KEY), true);
        $plans = is_array($stored) && $stored !== [] ? $stored : config('saas.plans', []);

        // Otomatis sertakan paket bawaan Lifetime jika database lama belum memilikinya
        if (isset($plans[Tenant::PLAN_PRO]) && ! isset($plans[Tenant::PLAN_LIFETIME]) && isset(config('saas.plans')[Tenant::PLAN_LIFETIME])) {
            $plans[Tenant::PLAN_LIFETIME] = config('saas.plans')[Tenant::PLAN_LIFETIME];
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
     * @return array{label: string, price: ?int, max_users: ?int, max_products: ?int}|null
     */
    public static function find(?string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * @param  array<string, array{label: string, price: ?int, max_users: ?int, max_products: ?int}>  $plans
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
