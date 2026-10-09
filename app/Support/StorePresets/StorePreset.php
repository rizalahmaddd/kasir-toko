<?php

namespace App\Support\StorePresets;

use App\Enums\StoreType;

/**
 * Data awal satu jenis toko: kategori & produk contoh, setting kasir, fitur yang dimatikan, kapabilitas
 * usaha yang dinyalakan, skema atribut produk, satuan yang disarankan, dan peran tambahan.
 */
abstract class StorePreset
{
    abstract public function type(): StoreType;

    /**
     * Produk per kategori, sebagai PresetProduct atau tuple [name, cost_price, price, unit, min_stock].
     *
     * @return array<string, list<PresetProduct|array{0: string, 1: int, 2: int, 3: string, 4: int|null}>>
     */
    abstract protected function rows(): array;

    /**
     * @return array<string, list<PresetProduct>>
     */
    public function catalog(): array
    {
        return array_map(fn (array $rows) => array_map(PresetProduct::from(...), $rows), $this->rows());
    }

    /**
     * Setting kasir yang menimpa nilai dasar StorePresets.
     *
     * @return array<string, string>
     */
    public function settings(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function disabledFeatures(): array
    {
        return [];
    }

    /**
     * Kapabilitas usaha (Features modul business) yang dinyalakan, mis. "business.multi-unit".
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        return [];
    }

    /**
     * @return list<AttributeField>
     */
    public function productAttributes(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function suggestedUnits(): array
    {
        return [];
    }

    /**
     * Grup pilihan tambahan contoh; hanya dibuat bila kapabilitas Pilihan Tambahan dinyalakan.
     *
     * @return list<PresetModifierGroup>
     */
    public function modifierGroups(): array
    {
        return [];
    }

    /**
     * Peran tambahan yang dibuat saat preset diterapkan, nama peran => izin.
     *
     * @return array<string, list<string>>
     */
    public function extraRoles(): array
    {
        return [];
    }
}
