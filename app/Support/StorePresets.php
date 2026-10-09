<?php

namespace App\Support;

use App\Enums\StoreType;
use App\Support\StorePresets\AttributeField;
use App\Support\StorePresets\BakeryPreset;
use App\Support\StorePresets\BuildingSupplyPreset;
use App\Support\StorePresets\CafePreset;
use App\Support\StorePresets\FashionPreset;
use App\Support\StorePresets\MinimarketPreset;
use App\Support\StorePresets\OtherPreset;
use App\Support\StorePresets\PharmacyPreset;
use App\Support\StorePresets\PhoneCounterPreset;
use App\Support\StorePresets\PresetProduct;
use App\Support\StorePresets\RestaurantPreset;
use App\Support\StorePresets\StorePreset;
use App\Support\StorePresets\WarungPreset;

/**
 * Data awal per jenis toko, diterapkan oleh App\Services\StorePresetApplier. Isinya ada di kelas
 * App\Support\StorePresets\*Preset; kelas ini hanya pintu masuknya. Harga dalam rupiah bulat.
 */
class StorePresets
{
    /**
     * Fitur yang ditentukan preset. Sakelar lain tetap mengikuti pilihan pemilik.
     */
    public const MANAGED_FEATURES = ['pos.receivables', 'pos.customer-display'];

    private const BASE_SETTINGS = [
        'pos.tax_enabled' => '0',
        'pos.tax_rate' => '11',
        'pos.tax_label' => 'PPN',
        'pos.allow_credit' => '0',
        'pos.allow_negative_stock' => '0',
        'pos.payment_methods' => '["cash","qris","transfer","card"]',
        'pos.quick_cash' => '[10000,20000,50000,100000]',
        'pos.receipt_footer' => 'Terima kasih atas kunjungan Anda',
        'pos.block_expired_sale' => '1',
        'pos.expiry_warning_days' => '30',
        'pos.prescription_mode' => 'strict',
        'pos.allow_controlled_drugs' => '0',
    ];

    /**
     * @var array<string, class-string<StorePreset>>
     */
    private const CLASSES = [
        'warung' => WarungPreset::class,
        'minimarket' => MinimarketPreset::class,
        'kafe' => CafePreset::class,
        'restoran' => RestaurantPreset::class,
        'fashion' => FashionPreset::class,
        'bangunan' => BuildingSupplyPreset::class,
        'konter' => PhoneCounterPreset::class,
        'apotek' => PharmacyPreset::class,
        'bakery' => BakeryPreset::class,
        'lainnya' => OtherPreset::class,
    ];

    public static function for(StoreType $type): StorePreset
    {
        return app(self::CLASSES[$type->value]);
    }

    /**
     * @return list<string>
     */
    public static function categories(StoreType $type): array
    {
        return array_keys(self::for($type)->catalog());
    }

    /**
     * @return array<string, list<PresetProduct>>
     */
    public static function catalog(StoreType $type): array
    {
        return self::for($type)->catalog();
    }

    public static function sampleProductCount(StoreType $type): int
    {
        return array_sum(array_map('count', self::catalog($type)));
    }

    /**
     * Semua kunci yang ditulis preset, supaya menerapkan preset kedua mengganti preset pertama sepenuhnya.
     *
     * @return array<string, string>
     */
    public static function settings(StoreType $type): array
    {
        return array_replace(self::BASE_SETTINGS, self::for($type)->settings());
    }

    /**
     * @return list<string>
     */
    public static function disabledFeatures(StoreType $type): array
    {
        return self::for($type)->disabledFeatures();
    }

    /**
     * @return list<string>
     */
    public static function capabilities(StoreType $type): array
    {
        return self::for($type)->capabilities();
    }

    /**
     * @return list<AttributeField>
     */
    public static function productAttributes(StoreType $type): array
    {
        return self::for($type)->productAttributes();
    }

    /**
     * @return list<string>
     */
    public static function suggestedUnits(StoreType $type): array
    {
        return self::for($type)->suggestedUnits();
    }

    /**
     * Kunci atribut yang ikut pencarian produk, dari semua jenis toko (atribut lama tetap bisa dicari
     * walau jenis toko berganti).
     *
     * @return list<string>
     */
    public static function searchableAttributeKeys(): array
    {
        $keys = [];

        foreach (StoreType::cases() as $type) {
            foreach (self::productAttributes($type) as $field) {
                $field->searchable && $keys[] = $field->key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Setting yang penting saat memilih, sudah di-decode untuk tampilan dan API.
     *
     * @return array{tax_enabled: bool, tax_rate: float, tax_label: string, allow_credit: bool, allow_negative_stock: bool, payment_methods: list<string>, quick_cash: list<int>, receipt_footer: string}
     */
    public static function settingsSummary(StoreType $type): array
    {
        $settings = self::settings($type);

        return [
            'tax_enabled' => $settings['pos.tax_enabled'] === '1',
            'tax_rate' => (float) $settings['pos.tax_rate'],
            'tax_label' => $settings['pos.tax_label'],
            'allow_credit' => $settings['pos.allow_credit'] === '1',
            'allow_negative_stock' => $settings['pos.allow_negative_stock'] === '1',
            'payment_methods' => json_decode($settings['pos.payment_methods'], true),
            'quick_cash' => json_decode($settings['pos.quick_cash'], true),
            'receipt_footer' => $settings['pos.receipt_footer'],
        ];
    }
}
