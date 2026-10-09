<?php

namespace App\Support;

use App\Models\Outlet;
use App\Models\Scopes\TenantScope;
use ArrayObject;

/**
 * Lapisan sakelar fitur per outlet di atas Features. Kapabilitas usaha outlet disimpan di
 * `outlets.capabilities` (null = mengikuti daftar toko), fitur kasir yang dikelola preset di
 * `outlets.disabled_features`. Fitur yang mati di level toko tetap mati di semua outlet.
 */
class OutletFeatures
{
    private const CACHE_KEY = 'features.outlets';

    public static function allows(string $key, ?int $outletId): bool
    {
        if ($outletId === null || ! str_contains($key, '.')) {
            return true;
        }

        $outlet = self::outlet($outletId);

        if ($outlet === null) {
            return true;
        }

        if (Features::isOptIn($key)) {
            return in_array($key, $outlet->capabilities ?? Features::enabledKeys(), true);
        }

        if (in_array($key, StorePresets::MANAGED_FEATURES, true)) {
            return ! in_array($key, $outlet->disabled_features ?? [], true);
        }

        return true;
    }

    /**
     * Kapabilitas usaha yang menyala di outlet.
     *
     * @return list<string>
     */
    public static function capabilities(int $outletId): array
    {
        return array_values(array_filter(Features::optInFeatures(), fn (string $key) => Features::enabledAt($key, $outletId)));
    }

    /**
     * @param  list<string>  $keys
     */
    public static function setDisabled(Outlet $outlet, array $keys): void
    {
        $valid = array_values(array_intersect(StorePresets::MANAGED_FEATURES, $keys));

        $outlet->forceFill(['disabled_features' => $valid === [] ? null : $valid])->save();
    }

    public static function flush(): void
    {
        app()->forgetInstance(self::CACHE_KEY);
    }

    private static function outlet(int $outletId): ?Outlet
    {
        // Ditahan per request seperti Features: kasir memanggil ini puluhan kali per render.
        if (! app()->bound(self::CACHE_KEY)) {
            app()->scoped(self::CACHE_KEY, fn () => new ArrayObject);
        }

        /** @var ArrayObject<int, Outlet|null> $outlets */
        $outlets = app(self::CACHE_KEY);

        if (! $outlets->offsetExists($outletId)) {
            $outlets[$outletId] = Outlet::query()->withoutGlobalScope(TenantScope::class)
                ->select(['id', 'tenant_id', 'capabilities', 'disabled_features'])
                ->find($outletId);
        }

        return $outlets[$outletId];
    }
}
