<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value identitas perusahaan & branding aplikasi (kop surat cetak, nama/logo di layout).
 * Branding dibaca di setiap halaman (judul tab, sidebar), jadi seluruh tabel di-cache sebagai
 * satu array dan cache-nya dibuang setiap kali ada nilai yang ditulis.
 */
class Setting extends Model
{
    use Auditable;

    private const CACHE_KEY = 'settings.all';

    protected $fillable = ['key', 'value'];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::allValues()[$key] ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        // Event saved tidak jalan saat model event dimatikan (mis. DatabaseSeeder), jadi cache dibuang di sini juga.
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string|null>
     */
    protected static function allValues(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());
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
