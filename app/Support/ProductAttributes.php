<?php

namespace App\Support;

use App\Enums\StoreType;
use App\Support\StorePresets\AttributeField;

/**
 * Skema atribut produk toko aktif, gabungan jenis usaha toko dan semua outletnya (katalog dipakai
 * bersama). Kosong selama kapabilitas Atribut Produk Khusus mati; nilai yang sudah tersimpan tetap
 * ada di products.custom_attributes.
 */
class ProductAttributes
{
    /**
     * Kunci yang sama di dua jenis usaha memakai definisi jenis toko, lalu urutan prioritas outlet.
     *
     * @return list<AttributeField>
     */
    public static function fields(): array
    {
        if (! Features::enabled('business.product-attributes')) {
            return [];
        }

        return collect(self::storeTypes())
            ->flatMap(fn (StoreType $type) => StorePresets::productAttributes($type))
            ->unique(fn (AttributeField $field) => $field->key)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function suggestedUnits(): array
    {
        return collect(self::storeTypes())
            ->flatMap(fn (StoreType $type) => StorePresets::suggestedUnits($type))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<StoreType>
     */
    private static function storeTypes(): array
    {
        return app(CurrentTenant::class)->get()?->storeTypes() ?? [];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(string $prefix): array
    {
        $rules = [$prefix => ['nullable', 'array']];

        foreach (self::fields() as $field) {
            $rules["{$prefix}.{$field->key}"] = $field->rules();
        }

        return $rules;
    }

    /**
     * Gabungkan isian baru ke nilai lama: kunci yang tidak ada di skema sekarang (mis. jenis toko berganti)
     * tetap disimpan, isian kosong dihapus.
     *
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    public static function merge(?array $current, array $input): ?array
    {
        $merged = $current ?? [];

        foreach (self::fields() as $field) {
            if (! array_key_exists($field->key, $input)) {
                continue;
            }

            $value = $input[$field->key];
            $value = match ($field->type) {
                'number' => $value === null || $value === '' ? null : (float) str_replace(',', '.', (string) $value),
                'bool' => $value === null || $value === '' ? null : (bool) $value,
                default => is_string($value) ? trim($value) : $value,
            };

            if ($value === null || $value === '') {
                unset($merged[$field->key]);
            } else {
                $merged[$field->key] = $value;
            }
        }

        return $merged === [] ? null : $merged;
    }

    /**
     * Atribut yang dicetak di struk, sebagai "Label: nilai".
     *
     * @param  array<string, mixed>|null  $values
     * @return list<string>
     */
    public static function receiptLines(?array $values): array
    {
        $lines = [];

        foreach (self::fields() as $field) {
            if ($field->onReceipt && ($display = $field->display($values[$field->key] ?? null)) !== null) {
                $lines[] = "{$field->label}: {$display}";
            }
        }

        return $lines;
    }
}
