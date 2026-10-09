<?php

namespace App\Support\StorePresets;

use App\Enums\DrugClass;

/**
 * Produk contoh di preset. min_stock null berarti dibuat saat dipesan atau digital, jadi stoknya tidak dilacak.
 */
final class PresetProduct
{
    /**
     * @param  list<array{name: string, factor: float, price: int|null}>  $units
     * @param  array<string, string|int|float|bool>  $attributes
     * @param  list<array{name: string, values: list<string>}>  $variantOptions
     */
    public function __construct(
        public readonly string $name,
        public readonly int $cost,
        public readonly int $price,
        public readonly string $unit,
        public readonly ?int $minStock,
        public readonly array $units = [],
        public readonly array $attributes = [],
        public readonly ?DrugClass $drugClass = null,
        public readonly bool $trackBatch = false,
        public readonly array $variantOptions = [],
        public readonly bool $trackSerial = false,
        public readonly ?int $warrantyDays = null,
    ) {}

    public static function make(string $name, int $cost, int $price, string $unit, ?int $minStock): self
    {
        return new self($name, $cost, $price, $unit, $minStock);
    }

    /**
     * @param  PresetProduct|array{0: string, 1: int, 2: int, 3: string, 4: int|null}  $row
     */
    public static function from(self|array $row): self
    {
        return $row instanceof self ? $row : new self($row[0], $row[1], $row[2], $row[3], $row[4]);
    }

    /**
     * Satuan tambahan, masing-masing [nama, faktor terhadap satuan dasar, harga atau null untuk faktor × harga].
     *
     * @param  array{0: string, 1: int|float, 2?: int|null}  ...$units
     */
    public function units(array ...$units): self
    {
        return $this->with(units: array_map(fn (array $unit) => ['name' => $unit[0], 'factor' => (float) $unit[1], 'price' => $unit[2] ?? null], $units));
    }

    /**
     * @param  array<string, string|int|float|bool>  $attributes
     */
    public function attributes(array $attributes): self
    {
        return $this->with(attributes: $attributes);
    }

    public function drug(DrugClass $class): self
    {
        return $this->with(drugClass: $class);
    }

    public function batch(): self
    {
        return $this->with(trackBatch: true);
    }

    /**
     * Pilihan varian, mis. ['Ukuran' => ['S', 'M', 'L'], 'Warna' => ['Hitam', 'Putih']].
     *
     * @param  array<string, list<string>>  $options
     */
    public function variants(array $options): self
    {
        return $this->with(variantOptions: array_map(fn (string $name, array $values) => ['name' => $name, 'values' => $values], array_keys($options), $options));
    }

    public function serial(int $warrantyDays): self
    {
        return $this->with(trackSerial: true, warrantyDays: $warrantyDays);
    }

    public function requiresPrescription(): bool
    {
        return $this->drugClass?->requiresPrescriptionByDefault() ?? false;
    }

    private function with(mixed ...$changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
