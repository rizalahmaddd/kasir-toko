<?php

namespace App\Http\Resources\V1\MasterData;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\Pos\ModifierService;
use App\Support\CurrentOutlet;
use App\Support\Features;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Produk untuk outlet yang sedang dipakai (header `X-Outlet-Id`). Harga dalam rupiah bulat; `price`
 * adalah harga jual efektif di outlet itu (`base_price` kalau outlet tidak punya harga khusus,
 * `has_outlet_price` menandainya). `stock` dan `min_stock` string desimal (3 digit) milik outlet dan
 * hanya bermakna kalau `track_stock` true. `stock_total` (semua outlet) hanya diisi untuk akun
 * berizin laporan semua outlet di toko multi-outlet. `outlet_prices` (harga khusus tiap outlet) hanya ada di detail,
 * tambah, dan ubah produk. `custom_attributes` mengikuti skema `product_attributes` di `meta`; `drug_class`
 * dan `requires_prescription` dipakai kapabilitas resep; `units` satuan jual lain (stok tetap satuan dasar).
 * `price_tiers` harga grosir satuan dasar (kosong bila Harga Grosir mati), `modifier_groups` pilihan tambahan
 * yang ditanyakan kasir (hanya grup & pilihan aktif), `components` bahan racikan per 1 satuan dasar.
 * `variants` SKU anak (hanya untuk induk di katalog kasir; induk tidak bisa di-checkout), `track_serial` wajib memilih
 * nomor seri saat dijual (`matched_serial` terisi bila lookup cocok dengan nomor seri). `near_expiry_quantity` (katalog
 * kasir) unit dari batch hampir kedaluwarsa yang mendapat potongan ED dekat (`near_expiry_discount_percent` di config).
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array{id: int, sku: string, barcode: string|null, name: string, unit: string, category: CategoryResource|null, cost_price: int, price: int, base_price: int, has_outlet_price: bool, track_stock: bool, stock: numeric-string, stock_total: numeric-string|null, min_stock: numeric-string, is_low_stock: bool, image_url: string|null, is_active: bool, custom_attributes: array<string, mixed>|null, drug_class: string|null, drug_class_label: string|null, requires_prescription: bool, track_batch: bool, units: list<ProductUnitResource>, price_tiers: list<array{min_quantity: numeric-string, price: int}>, modifier_groups: list<array<string, mixed>>, modifier_group_ids: list<int>, components: list<array{component_id: int, name: string|null, unit: string|null, quantity: numeric-string}>, matched_unit_id?: int, outlet_prices?: list<array{outlet_id: int, price: int}>}
     */
    public function toArray(Request $request): array
    {
        if (! array_key_exists('outlet_stock', $this->resource->getAttributes())) {
            $this->resource->loadOutletData();
        }

        $showTotal = app(CurrentOutlet::class)->isMultiOutlet() && $request->user()?->can('reports.all-outlets');

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'unit' => $this->unit,
            'category' => $this->category ? new CategoryResource($this->category) : null,
            'cost_price' => $this->cost_price,
            'price' => $this->effectivePrice(),
            'base_price' => $this->price,
            'has_outlet_price' => $this->hasOutletPrice(),
            'track_stock' => $this->track_stock,
            'stock' => number_format($this->outletStock(), 3, '.', ''),
            'stock_total' => $showTotal ? number_format((float) $this->stock, 3, '.', '') : null,
            'min_stock' => number_format($this->outletMinStock(), 3, '.', ''),
            'is_low_stock' => $this->isLowStock(),
            'image_url' => $this->imageUrl(),
            'is_active' => $this->is_active,
            'custom_attributes' => $this->custom_attributes ?: null,
            'drug_class' => $this->drug_class,
            'drug_class_label' => $this->drugClass()?->label(),
            'requires_prescription' => $this->requires_prescription,
            'track_batch' => $this->track_batch,
            'matched_unit_id' => $this->when($this->resource->getAttribute('matched_unit_id') !== null, fn () => (int) $this->resource->getAttribute('matched_unit_id')),
            'units' => ! $request->routeIs('api.v1.pos.*') || Features::enabledAt('business.multi-unit') ? $this->units->map(fn (ProductUnit $unit) => new ProductUnitResource($unit, $this->effectivePrice()))->values()->all() : [],
            'price_tiers' => $this->featureOn($request, 'business.tiered-price') ? $this->priceTiers->map(fn ($tier) => ['min_quantity' => number_format((float) $tier->min_quantity, 3, '.', ''), 'price' => (int) $tier->price])->values()->all() : [],
            'modifier_groups' => $this->featureOn($request, 'business.modifiers') ? ModifierService::payload($this->resource) : [],
            'modifier_group_ids' => $this->featureOn($request, 'business.modifiers') ? $this->modifierGroups->pluck('id')->values()->all() : [],
            'parent_id' => $this->parent_id,
            'variant_label' => $this->variantLabel(),
            'variant_options' => $this->featureOn($request, 'business.variants') ? ($this->variant_options ?: []) : [],
            'variants' => $this->featureOn($request, 'business.variants') && $this->isVariantParent() && $this->relationLoaded('variants')
                ? $this->variants->map(fn (Product $child) => [
                    'id' => $child->id,
                    'name' => $child->name,
                    'label' => $child->variantLabel(),
                    'sku' => $child->sku,
                    'barcode' => $child->barcode,
                    'price' => $child->effectivePrice(),
                    'stock' => number_format($child->outletStock(), 3, '.', ''),
                    'track_stock' => $child->track_stock,
                    'track_serial' => $child->tracksSerials(),
                ])->values()->all()
                : [],
            'track_serial' => $this->tracksSerials(),
            'near_expiry_quantity' => $this->when($this->resource->getAttribute('near_expiry_quantity') !== null, fn () => number_format((float) $this->resource->getAttribute('near_expiry_quantity'), 3, '.', '')),
            'warranty_days' => $this->warranty_days,
            'matched_serial' => $this->when($this->resource->getAttribute('matched_serial') !== null, fn () => (string) $this->resource->getAttribute('matched_serial')),
            'components' => $this->featureOn($request, 'business.components') ? $this->components->map(fn ($component) => [
                'component_id' => (int) $component->component_id,
                'name' => $component->component?->name,
                'unit' => $component->component?->unit,
                'quantity' => number_format((float) $component->quantity, 3, '.', ''),
            ])->values()->all() : [],
            'outlet_prices' => $this->whenLoaded('outletPrices', fn () => $this->outletPrices->map(fn ($row) => ['outlet_id' => (int) $row->outlet_id, 'price' => (int) $row->price])->values()->all()),
        ];
    }

    /**
     * Di endpoint kasir sakelar dibaca per outlet aktif; di master data tetap level toko supaya form
     * tidak kehilangan field yang dipakai outlet lain.
     */
    private function featureOn(Request $request, string $key): bool
    {
        return $request->routeIs('api.v1.pos.*') ? Features::enabledAt($key) : Features::enabled($key);
    }
}
