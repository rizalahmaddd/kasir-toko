<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Pengaturan layar pelanggan (key "display.*" di tabel settings).
 */
class CustomerDisplaySettings
{
    public const MAX_SLIDES = 8;

    public const DEFAULTS = [
        'display.enabled' => '1',
        'display.theme' => 'dark',
        'display.welcome' => 'Selamat datang! Terima kasih sudah berbelanja di sini.',
        'display.promo_text' => '',
        'display.show_items' => '1',
        'display.thank_you_seconds' => '6',
        'display.slides' => '[]',
        'display.slide_seconds' => '8',
    ];

    public static function get(string $key): string
    {
        return (string) Setting::get($key, self::DEFAULTS[$key] ?? null);
    }

    public static function enabled(): bool
    {
        return self::get('display.enabled') === '1';
    }

    public static function theme(): string
    {
        return self::get('display.theme') === 'light' ? 'light' : 'dark';
    }

    /**
     * @return list<string> path di disk public
     */
    public static function slides(): array
    {
        $slides = json_decode(self::get('display.slides'), true);

        return array_values(array_filter(is_array($slides) ? $slides : [], 'is_string'));
    }

    /**
     * @return list<string>
     */
    public static function slideUrls(): array
    {
        return array_map(fn (string $path) => Storage::disk('public')->url($path), self::slides());
    }

    /**
     * @return array<string, mixed>
     */
    public static function forScreen(): array
    {
        return [
            'theme' => self::theme(),
            'welcome' => self::get('display.welcome'),
            'promo' => self::get('display.promo_text'),
            'showItems' => self::get('display.show_items') === '1',
            'thankYouSeconds' => max(2, min(60, (int) self::get('display.thank_you_seconds'))),
            'slideSeconds' => max(3, min(60, (int) self::get('display.slide_seconds'))),
            'slides' => self::slideUrls(),
        ];
    }
}
