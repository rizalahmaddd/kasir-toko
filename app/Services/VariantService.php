<?php

namespace App\Services;

use App\Models\Product;
use App\Support\PlanLimits;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Varian produk: induk menyimpan pilihan (mis. Ukuran: S/M/L, Warna: Hitam/Putih) dan setiap kombinasi
 * menjadi produk anak biasa dengan SKU, barcode, stok, dan harga sendiri. Semua jalur stok, kasir, dan
 * laporan tetap memakai tabel products tanpa cabang logika baru. SKU anak dihitung dalam batas paket.
 */
class VariantService
{
    public function __construct(private DocumentNumberGenerator $numbers) {}

    /**
     * @param  list<array{name: string, values: list<string>}>  $options
     * @return array{created: int, removed: int}
     *
     * @throws ValidationException
     */
    public function sync(Product $parent, array $options): array
    {
        $options = $this->normalize($options);

        if ($parent->parent_id !== null) {
            throw ValidationException::withMessages(['variant_options' => 'Produk ini sendiri adalah varian, jadi tidak bisa punya varian lagi.']);
        }

        $combinations = $this->combinations($options);

        if (count($combinations) > 100) {
            throw ValidationException::withMessages(['variant_options' => 'Maksimal 100 kombinasi varian per produk.']);
        }

        $existing = $parent->variants()->get();
        $keyOf = fn (array $values): string => json_encode($values);
        $byKey = $existing->keyBy(fn (Product $child) => $keyOf($child->variant_values ?? []));
        $created = 0;

        foreach ($combinations as $values) {
            if ($byKey->has($keyOf($values))) {
                $byKey->get($keyOf($values))->update(['name' => $this->childName($parent, $values)]);

                continue;
            }

            PlanLimits::ensureCanAdd('products', 'variant_options');

            $parent->variants()->create([
                'category_id' => $parent->category_id,
                'sku' => $this->numbers->next('PRD', 5),
                'name' => $this->childName($parent, $values),
                'unit' => $parent->unit,
                'cost_price' => $parent->cost_price,
                'price' => $parent->price,
                'track_stock' => true,
                'stock' => 0,
                'min_stock' => 0,
                'is_active' => $parent->is_active,
                'variant_values' => $values,
            ]);
            $created++;
        }

        $wanted = collect($combinations)->map($keyOf);
        $removed = $existing->reject(fn (Product $child) => $wanted->contains($keyOf($child->variant_values ?? [])));
        $removed->each->delete();

        // Induk tidak dijual langsung dan tidak punya stok sendiri; stoknya ada di tiap SKU anak.
        $parent->forceFill(['variant_options' => $options === [] ? null : $options, 'track_stock' => $options === [] ? $parent->track_stock : false])->save();

        return ['created' => $created, 'removed' => $removed->count()];
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return list<array{name: string, values: list<string>}>
     */
    private function normalize(array $options): array
    {
        $result = [];

        foreach ($options as $index => $option) {
            $name = trim((string) ($option['name'] ?? ''));
            $values = collect(is_array($option['values'] ?? null) ? $option['values'] : preg_split('/\s*,\s*/', (string) ($option['values'] ?? '')))
                ->map(fn ($value) => trim((string) $value))->filter()->unique()->values()->all();

            if ($name === '' && $values === []) {
                continue;
            }

            if ($name === '' || $values === []) {
                throw ValidationException::withMessages(["variant_options.{$index}.name" => 'Isi nama pilihan (mis. Ukuran) dan nilainya (mis. S, M, L).']);
            }

            $result[] = ['name' => mb_substr($name, 0, 20), 'values' => array_map(fn (string $value) => mb_substr($value, 0, 20), $values)];
        }

        return $result;
    }

    /**
     * @param  list<array{name: string, values: list<string>}>  $options
     * @return list<array<string, string>>
     */
    private function combinations(array $options): array
    {
        $result = [[]];

        foreach ($options as $option) {
            $next = [];

            foreach ($result as $partial) {
                foreach ($option['values'] as $value) {
                    $next[] = [...$partial, $option['name'] => $value];
                }
            }

            $result = $next;
        }

        return $options === [] ? [] : $result;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function childName(Product $parent, array $values): string
    {
        return mb_substr($parent->name.' - '.implode(' / ', $values), 0, 150);
    }

    /**
     * Ringkasan anak untuk form & API: stok outlet aktif, harga, barcode.
     *
     * @return Collection<int, array{id: int, name: string, label: ?string, sku: string, barcode: ?string, price: int, stock: float, is_active: bool}>
     */
    public static function summary(Product $parent): Collection
    {
        return Product::query()->withOutletData()->where('parent_id', $parent->id)->orderBy('name')->get()->map(fn (Product $child) => [
            'id' => $child->id,
            'name' => $child->name,
            'label' => $child->variantLabel(),
            'sku' => $child->sku,
            'barcode' => $child->barcode,
            'price' => $child->effectivePrice(),
            'stock' => $child->outletStock(),
            'is_active' => $child->is_active,
        ]);
    }
}
