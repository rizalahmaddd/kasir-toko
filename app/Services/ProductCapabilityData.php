<?php

namespace App\Services;

use App\Enums\DrugClass;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\ProductUnit;
use App\Services\Pos\BatchService;
use App\Support\Features;
use App\Support\ProductAttributes;
use App\Support\TenantRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Data produk milik kapabilitas usaha: atribut khusus, golongan obat & wajib resep, lacak batch, dan
 * satuan tambahan. Dipakai form produk web dan API supaya aturannya sama. Isian dari kapabilitas yang
 * mati atau yang tidak dikirim klien dibiarkan apa adanya.
 */
class ProductCapabilityData
{
    public function __construct(private BatchService $batches, private VariantService $variants) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            ...ProductAttributes::rules('custom_attributes'),
            'drug_class' => ['nullable', Rule::enum(DrugClass::class)],
            'requires_prescription' => ['boolean'],
            'track_batch' => ['boolean'],
            'units' => ['nullable', 'array', 'max:10'],
            'units.*.id' => ['nullable', 'integer'],
            'units.*.name' => ['required', 'string', 'max:20'],
            'units.*.factor' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'units.*.price' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'units.*.barcode' => ['nullable', 'string', 'max:64'],
            'units.*.is_default_sale' => ['boolean'],
            'price_tiers' => ['nullable', 'array', 'max:10'],
            'price_tiers.*.min_quantity' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'price_tiers.*.price' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'modifier_group_ids' => ['nullable', 'array', 'max:20'],
            'modifier_group_ids.*' => ['integer', TenantRule::exists('modifier_groups', 'id')->whereNull('deleted_at')],
            'components' => ['nullable', 'array', 'max:20'],
            'components.*.component_id' => ['required', 'integer', TenantRule::exists('products', 'id')->whereNull('deleted_at')],
            'components.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'variant_options' => ['nullable', 'array', 'max:3'],
            'variant_options.*.name' => ['nullable', 'string', 'max:20'],
            'variant_options.*.values' => ['nullable'],
            'track_serial' => ['boolean'],
            'warranty_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'units.*.name.required' => 'Nama satuan wajib diisi.',
            'units.*.factor.required' => 'Isi berapa satuan dasar dalam satuan ini.',
            'units.*.factor.gt' => 'Isi satuan harus lebih dari 0.',
            'price_tiers.*.min_quantity.required' => 'Isi jumlah minimal pembelian.',
            'price_tiers.*.min_quantity.gt' => 'Jumlah minimal harus lebih dari 0.',
            'price_tiers.*.price.required' => 'Isi harga grosirnya.',
            'components.*.component_id.required' => 'Pilih bahannya.',
            'components.*.quantity.required' => 'Isi jumlah bahan per satuan.',
            'components.*.quantity.gt' => 'Jumlah bahan harus lebih dari 0.',
        ];
    }

    /**
     * Isi kolom produk sebelum disimpan.
     *
     * @param  array<string, mixed>  $data
     */
    public function fill(Product $product, array $data): void
    {
        if (array_key_exists('custom_attributes', $data) && Features::enabled('business.product-attributes')) {
            $product->custom_attributes = ProductAttributes::merge($product->custom_attributes, (array) ($data['custom_attributes'] ?? []));
        }

        if (Features::enabled('business.prescription')) {
            if (array_key_exists('drug_class', $data)) {
                $product->drug_class = filled($data['drug_class']) ? $data['drug_class'] : null;
            }

            if (array_key_exists('requires_prescription', $data)) {
                $product->requires_prescription = (bool) $data['requires_prescription'];
            } elseif ($product->isDirty('drug_class')) {
                $product->requires_prescription = $product->drugClass()?->requiresPrescriptionByDefault() ?? false;
            }
        }

        if (array_key_exists('track_batch', $data) && Features::enabled('business.batch-expiry')) {
            $product->track_batch = (bool) $data['track_batch'] && $product->track_stock;
        }

        if (Features::enabled('business.serial-number')) {
            if (array_key_exists('track_serial', $data)) {
                $product->track_serial = (bool) $data['track_serial'] && $product->track_stock;
            }

            if (array_key_exists('warranty_days', $data)) {
                $product->warranty_days = filled($data['warranty_days']) ? (int) $data['warranty_days'] : null;
            }
        }
    }

    /**
     * Langkah setelah produk tersimpan: satuan tambahan dan saldo batch awal.
     *
     * @param  array<string, mixed>  $data
     */
    public function afterSave(Product $product, array $data): void
    {
        if (array_key_exists('units', $data) && Features::enabled('business.multi-unit')) {
            $this->syncUnits($product, $data['units'] ?? []);
        }

        if (array_key_exists('price_tiers', $data) && Features::enabled('business.tiered-price')) {
            $this->syncTiers($product, $data['price_tiers'] ?? []);
        }

        if (array_key_exists('modifier_group_ids', $data) && Features::enabled('business.modifiers')) {
            $product->modifierGroups()->sync(collect($data['modifier_group_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->mapWithKeys(fn (int $id, int $order) => [$id => ['sort_order' => $order + 1]])->all());
        }

        if (array_key_exists('components', $data) && Features::enabled('business.components')) {
            $this->syncComponents($product, $data['components'] ?? []);
        }

        if (array_key_exists('variant_options', $data) && Features::enabled('business.variants')) {
            $this->variants->sync($product, array_values((array) ($data['variant_options'] ?? [])));
        }

        if ($product->wasChanged('track_batch') && $product->tracksBatches()) {
            $this->batches->reconcileProduct($product);
        }
    }

    /**
     * Stok awal di form produk tidak menyebut nomor seri, jadi produk bernomor seri mulai dari 0 dan stoknya
     * dicatat lewat Stok Masuk. Induk varian tidak punya stok sendiri.
     *
     * @throws ValidationException
     */
    public static function ensureInitialStockAllowed(Product $product, float $initialStock): void
    {
        if ($initialStock == 0.0) {
            return;
        }

        if ($product->tracksSerials()) {
            throw ValidationException::withMessages(['stock' => 'Produk bernomor seri dimulai dari stok 0. Catat stoknya lewat Stok Masuk beserta nomor serinya.']);
        }

        if ($product->isVariantParent()) {
            throw ValidationException::withMessages(['stock' => 'Produk induk varian tidak punya stok sendiri. Isi stok di tiap varian.']);
        }
    }

    /**
     * Barcode satuan dan barcode produk berbagi ruang yang sama: scanner kasir harus menemukan tepat satu barang.
     *
     * @param  list<array<string, mixed>>  $units
     *
     * @throws ValidationException
     */
    public static function ensureUniqueBarcodes(?int $productId, ?string $productBarcode, array $units, string $prefix = 'units'): void
    {
        $productBarcode = filled($productBarcode) ? trim($productBarcode) : null;
        $seen = $productBarcode ? [$productBarcode => 'barcode'] : [];
        $errors = [];

        if ($productBarcode && ProductUnit::query()->where('barcode', $productBarcode)->where('product_id', '!=', $productId ?? 0)->exists()) {
            $errors['barcode'] = 'Barcode ini sudah dipakai satuan jual produk lain.';
        }

        foreach ($units as $index => $unit) {
            $code = filled($unit['barcode'] ?? null) ? trim($unit['barcode']) : null;

            if ($code === null) {
                continue;
            }

            $taken = isset($seen[$code])
                || Product::query()->where('barcode', $code)->where('id', '!=', $productId ?? 0)->exists()
                || ProductUnit::query()->where('barcode', $code)->where('product_id', '!=', $productId ?? 0)->exists();

            if ($taken) {
                $errors["{$prefix}.{$index}.barcode"] = 'Barcode ini sudah dipakai produk atau satuan lain.';
            }

            $seen[$code] = true;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $tiers
     */
    private function syncTiers(Product $product, array $tiers): void
    {
        $rows = collect($tiers)->map(fn (array $tier) => ['min_quantity' => round((float) $tier['min_quantity'], 3), 'price' => (int) $tier['price']])->sortBy('min_quantity')->values();

        foreach ($rows as $index => $row) {
            if ($rows->where('min_quantity', $row['min_quantity'])->count() > 1) {
                throw ValidationException::withMessages(["price_tiers.{$index}.min_quantity" => 'Jumlah minimal ini sudah dipakai tingkat lain.']);
            }

            if ($row['price'] >= $product->price) {
                throw ValidationException::withMessages(["price_tiers.{$index}.price" => 'Harga grosir harus lebih murah dari harga jual biasa.']);
            }
        }

        $product->priceTiers()->delete();
        $rows->each(fn (array $row) => $product->priceTiers()->create($row));
    }

    /**
     * Bahan racikan tidak boleh produk itu sendiri atau produk racikan lain, supaya potongan stok tidak berputar.
     *
     * @param  list<array<string, mixed>>  $components
     */
    private function syncComponents(Product $product, array $components): void
    {
        $rows = collect($components)->map(fn (array $row) => ['component_id' => (int) $row['component_id'], 'quantity' => round((float) $row['quantity'], 3)]);
        $compound = ProductComponent::query()->whereIn('product_id', $rows->pluck('component_id'))->pluck('product_id')->all();

        foreach ($rows as $index => $row) {
            if ($row['component_id'] === $product->id || in_array($row['component_id'], $compound, true)) {
                throw ValidationException::withMessages(["components.{$index}.component_id" => 'Bahan tidak boleh produk ini sendiri atau produk racikan lain.']);
            }

            if ($rows->where('component_id', $row['component_id'])->count() > 1) {
                throw ValidationException::withMessages(["components.{$index}.component_id" => 'Bahan ini sudah ada di daftar.']);
            }
        }

        if ($rows->isNotEmpty() && ProductComponent::query()->where('component_id', $product->id)->exists()) {
            throw ValidationException::withMessages(['components' => 'Produk ini dipakai sebagai bahan racikan lain, jadi tidak bisa punya komposisi sendiri.']);
        }

        $product->components()->delete();
        $rows->each(fn (array $row) => $product->components()->create($row));
    }

    /**
     * @param  list<array<string, mixed>>  $units
     */
    private function syncUnits(Product $product, array $units): void
    {
        $names = [mb_strtolower($product->unit)];
        $keep = [];
        $defaultSet = false;
        $existing = $product->units()->get()->keyBy('id');

        foreach (array_values($units) as $order => $row) {
            $name = trim((string) $row['name']);

            if (in_array(mb_strtolower($name), $names, true)) {
                throw ValidationException::withMessages(["units.{$order}.name" => "Satuan {$name} sudah ada. Pakai nama satuan yang berbeda dari satuan dasar dan satuan lain."]);
            }

            $names[] = mb_strtolower($name);
            $isDefault = ! $defaultSet && (bool) ($row['is_default_sale'] ?? false);
            $defaultSet = $defaultSet || $isDefault;

            $attributes = [
                'name' => $name,
                'factor' => round((float) $row['factor'], 3),
                'price' => isset($row['price']) && $row['price'] !== '' ? (int) $row['price'] : null,
                'barcode' => filled($row['barcode'] ?? null) ? trim($row['barcode']) : null,
                'is_default_sale' => $isDefault,
                'sort_order' => $order + 1,
            ];

            $unit = isset($row['id']) ? $existing->get((int) $row['id']) : null;

            if ($unit) {
                $unit->update($attributes);
            } else {
                $unit = $product->units()->create($attributes);
            }

            $keep[] = $unit->id;
        }

        $existing->except($keep)->each->delete();
    }
}
