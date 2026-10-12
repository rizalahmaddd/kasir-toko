<?php

namespace App\Services\Pos;

use App\Models\Modifier;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\ProductStock;
use App\Models\SaleItemComponent;
use App\Support\Features;
use App\Support\NumberFormatter;
use App\Support\PosSettings;
use Illuminate\Support\Collection;

/**
 * Kebutuhan stok sebuah keranjang dalam satuan dasar, termasuk bahan racikan dan bahan pilihan tambahan.
 * Checkout, submit pesanan QR, dan penerbitan tiket dapur memakai hitungan yang sama supaya menu yang
 * lolos di satu tempat tidak ditolak di tempat lain.
 */
class StockDemandResolver
{
    public function __construct(private ModifierService $modifiers) {}

    /**
     * Potongan stok satu baris. Produk racikan yang stoknya tidak dilacak memotong bahannya;
     * source null berarti barang itu sendiri (dicatat per batch di sale_item_batches).
     *
     * @param  Collection<int, Collection<int, ProductComponent>>  $components
     * @param  list<array{product_id: int, quantity: float}>  $ingredients
     * @return list<array{product_id: int, quantity: float, source: ?string}>
     */
    public function forLine(Product $product, float $baseQuantity, float $quantity, Collection $components, array $ingredients): array
    {
        $demands = [];

        if ($product->track_stock) {
            $demands[] = ['product_id' => $product->id, 'quantity' => $baseQuantity, 'source' => null];
        } else {
            foreach ($components->get($product->id, []) as $component) {
                $demands[] = ['product_id' => (int) $component->component_id, 'quantity' => round((float) $component->quantity * $baseQuantity, 3), 'source' => SaleItemComponent::SOURCE_RECIPE];
            }
        }

        foreach ($ingredients as $ingredient) {
            $demands[] = ['product_id' => $ingredient['product_id'], 'quantity' => round($ingredient['quantity'] * $quantity, 3), 'source' => SaleItemComponent::SOURCE_MODIFIER];
        }

        return array_values(array_filter($demands, fn (array $demand) => $demand['quantity'] > 0));
    }

    /**
     * Dilewati bila outlet mengizinkan stok minus.
     *
     * @param  array<int, list<array{product_id: int, quantity: float, source: ?string}>>  $demands  per baris keranjang
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, ProductStock>|array<int, float>  $stocks
     *
     * @throws PosException
     */
    public function ensureAvailable(array $demands, Collection $products, Collection|array $stocks): void
    {
        if (PosSettings::allowNegativeStock()) {
            return;
        }

        $needed = collect($demands)->flatten(1)->groupBy('product_id')->map(fn ($rows) => round((float) $rows->sum('quantity'), 3));

        foreach ($needed as $productId => $quantity) {
            $product = $products->get($productId);
            $stock = $stocks[$productId] ?? null;
            $available = (float) ($stock instanceof ProductStock ? $stock->stock : ($stock ?? 0));

            if ($product && $product->track_stock && round($available - $quantity, 3) < 0) {
                $left = NumberFormatter::quantity(max(0, $available));

                throw new PosException("Stok {$product->name} tidak cukup (tersisa {$left} {$product->unit}).", 'insufficient_stock', ['stock' => [$product->id => $available], 'product_id' => $product->id, 'product_name' => $product->name]);
            }
        }
    }

    /**
     * Cek tanpa mengunci untuk keranjang yang hanya berisi satuan dasar (pesanan QR): dipakai sebelum
     * pesanan diterima dan sebelum tiket dapur terbit. Stok baru benar-benar terpotong saat checkout.
     *
     * @param  list<array{product_id: int, quantity: float|int|string, modifiers?: list<array<string, mixed>>|null}>  $items
     *
     * @throws PosException
     */
    public function ensureCartAvailable(array $items, int $outletId): void
    {
        if (PosSettings::allowNegativeStock() || $items === []) {
            return;
        }

        $productIds = collect($items)->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values();
        $components = Features::enabled('business.components')
            ? ProductComponent::query()->whereIn('product_id', $productIds)->get()->groupBy('product_id')
            : new Collection;
        $modifiers = Features::enabledAt('business.modifiers', $outletId) ? $this->modifiers->load($items) : new Collection;
        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
        $demands = [];

        foreach ($items as $index => $item) {
            $product = $products->get((int) $item['product_id']);

            if (! $product) {
                continue;
            }

            $ingredients = collect($item['modifiers'] ?? [])
                ->map(fn ($row) => is_array($row) ? $modifiers->get((int) ($row['id'] ?? 0)) : null)
                ->filter(fn (?Modifier $modifier) => $modifier !== null && $modifier->usesIngredient())
                ->map(fn (Modifier $modifier) => ['product_id' => (int) $modifier->product_id, 'quantity' => (float) $modifier->ingredient_quantity])
                ->values()->all();

            $quantity = (float) $item['quantity'];
            $demands[$index] = $this->forLine($product, $quantity, $quantity, $components, $ingredients);
        }

        $neededIds = collect($demands)->flatten(1)->pluck('product_id')->unique();
        $targets = Product::withTrashed()->whereIn('id', $neededIds)->get()->keyBy('id');
        $stocks = ProductStock::query()->where('outlet_id', $outletId)->whereIn('product_id', $neededIds)->pluck('stock', 'product_id')->map(fn ($stock) => (float) $stock)->all();

        $this->ensureAvailable($demands, $targets, $stocks);
    }
}
