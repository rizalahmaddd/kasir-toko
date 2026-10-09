<?php

namespace App\Support\StorePresets;

/**
 * Grup pilihan tambahan contoh di preset, dipasang ke semua produk contoh di kategori $categories.
 */
final class PresetModifierGroup
{
    /**
     * @param  list<array{0: string, 1: int}>  $options  [nama, harga tambahan]
     * @param  list<string>  $categories
     */
    public function __construct(
        public readonly string $name,
        public readonly int $min,
        public readonly ?int $max,
        public readonly array $options,
        public readonly array $categories,
    ) {}
}
