<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value identitas perusahaan & branding aplikasi (kop surat cetak, nama/logo di layout).
 * Baris dengan tenant_id NULL adalah nilai bawaan platform (mis. branding halaman login) yang
 * ditimpa nilai milik tenant aktif. Nilai per tenant di-cache sebagai satu array dan cache-nya
 * dibuang setiap kali ada nilai yang ditulis.
 */
class Setting extends Model
{
    use Auditable;

    protected $fillable = ['tenant_id', 'key', 'value'];

    protected static function booted(): void
    {
        static::saved(fn (Setting $setting) => Cache::forget(self::cacheKey($setting->tenant_id)));
        static::deleted(fn (Setting $setting) => Cache::forget(self::cacheKey($setting->tenant_id)));
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::allValues()[$key] ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        $tenantId = app(CurrentTenant::class)->id();

        static::query()->updateOrCreate(['tenant_id' => $tenantId, 'key' => $key], ['value' => $value]);

        // Event saved tidak jalan saat model event dimatikan (mis. DatabaseSeeder), jadi cache dibuang di sini juga.
        Cache::forget(self::cacheKey($tenantId));
    }

    /**
     * Nilai milik platform saja, tidak bisa ditimpa toko. Dipakai untuk pengaturan layanan
     * (paket, pendaftaran) supaya baris milik toko dengan kunci yang sama tidak ikut terbaca.
     */
    public static function platform(string $key, ?string $default = null): ?string
    {
        return static::valuesFor(null)[$key] ?? $default;
    }

    /**
     * @return array<string, string|null>
     */
    protected static function allValues(): array
    {
        $platform = static::valuesFor(null);
        $tenantId = app(CurrentTenant::class)->id();

        return $tenantId === null ? $platform : array_replace($platform, static::valuesFor($tenantId));
    }

    /**
     * @return array<string, string|null>
     */
    private static function valuesFor(?int $tenantId): array
    {
        return Cache::rememberForever(self::cacheKey($tenantId), fn () => static::query()
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId), fn ($query) => $query->whereNull('tenant_id'))
            ->pluck('value', 'key')
            ->all());
    }

    private static function cacheKey(?int $tenantId): string
    {
        return 'settings.'.($tenantId ?? 'platform');
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public static function putMany(array $values): void
    {
        foreach ($values as $key => $value) {
            static::put($key, $value);
        }
    }
}
