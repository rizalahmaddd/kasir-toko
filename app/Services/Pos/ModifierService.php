<?php

namespace App\Services\Pos;

use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Memeriksa pilihan tambahan di keranjang terhadap data terbaru. Transaksi online ditolak bila pilihannya
 * sudah dihapus, harganya berubah, atau aturan wajib/maksimal grup dilanggar. Transaksi offline sudah
 * terjadi, jadi nama & harga dari payload dipakai apa adanya dan transaksinya ditandai.
 */
class ModifierService
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @return Collection<int, Modifier>
     */
    public function load(array $items): Collection
    {
        $ids = collect($items)->flatMap(fn (array $item) => collect($item['modifiers'] ?? [])->pluck('id'))->filter()->map(fn ($id) => (int) $id)->unique();

        return $ids->isEmpty() ? new Collection : Modifier::withTrashed()->with('group')->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, Modifier>  $modifiers
     * @return array{lines: array<int, array{total: int, snapshot: list<array{id: int, name: string, group: ?string, price: int}>, ingredients: list<array{product_id: int, quantity: float}>}>, flags: list<string>}
     *
     * @throws PosException
     */
    public function resolve(array $items, Collection $products, Collection $modifiers, bool $offline): array
    {
        $lines = [];
        $flags = [];
        $unavailable = [];
        $priceChanges = [];
        $missingRequired = null;
        $groupsByProduct = $this->attachedGroups($items);

        foreach ($items as $index => $item) {
            $chosen = array_values(array_filter($item['modifiers'] ?? [], 'is_array'));
            $line = ['total' => 0, 'snapshot' => [], 'ingredients' => []];
            $product = $products->get((int) $item['product_id']);
            $attached = $groupsByProduct->get((int) $item['product_id'], new Collection);
            $countByGroup = [];

            foreach ($chosen as $row) {
                $modifier = $modifiers->get((int) ($row['id'] ?? 0));
                $usable = $modifier && ! $modifier->trashed() && $modifier->is_active && $attached->contains('id', $modifier->modifier_group_id);
                $sentPrice = (int) ($row['price'] ?? 0);

                if (! $usable) {
                    if (! $offline) {
                        $unavailable[] = (int) ($row['id'] ?? 0);

                        continue;
                    }

                    $flags[] = 'modifier_snapshot';
                    $line['snapshot'][] = ['id' => (int) ($row['id'] ?? 0), 'name' => mb_substr((string) ($row['name'] ?? $modifier?->name ?? 'Pilihan'), 0, 60), 'group' => $modifier?->group?->name, 'price' => max(0, $sentPrice)];
                    $line['total'] += max(0, $sentPrice);

                    continue;
                }

                if ($sentPrice !== (int) $modifier->price) {
                    if (! $offline) {
                        $priceChanges[$modifier->id] = ['price' => (int) $modifier->price, 'name' => $modifier->name];

                        continue;
                    }

                    $flags[] = 'modifier_snapshot';
                }

                $price = $offline ? max(0, $sentPrice) : (int) $modifier->price;
                $countByGroup[$modifier->modifier_group_id] = ($countByGroup[$modifier->modifier_group_id] ?? 0) + 1;
                $line['snapshot'][] = ['id' => $modifier->id, 'name' => $modifier->name, 'group' => $modifier->group?->name, 'price' => $price];
                $line['total'] += $price;

                if ($modifier->usesIngredient()) {
                    $line['ingredients'][] = ['product_id' => (int) $modifier->product_id, 'quantity' => (float) $modifier->ingredient_quantity];
                }
            }

            if (! $offline && $product && $missingRequired === null) {
                foreach ($attached as $group) {
                    $count = $countByGroup[$group->id] ?? 0;

                    if ($count < $group->min_select || ($group->max_select !== null && $count > $group->max_select)) {
                        $missingRequired = "{$group->name} untuk {$product->name} ({$group->ruleLabel()})";

                        break;
                    }
                }
            }

            $lines[$index] = $line;
        }

        if ($unavailable !== []) {
            throw new PosException('Ada pilihan tambahan yang sudah dihapus atau tidak berlaku untuk produknya. Ubah pilihan di keranjang lalu bayar lagi.', 'modifier_unavailable', ['modifier_ids' => array_values(array_unique($unavailable))]);
        }

        if ($priceChanges !== []) {
            $names = collect($priceChanges)->pluck('name')->take(3)->implode(', ');

            throw new PosException("Harga pilihan {$names} sudah berubah. Keranjang diperbarui, periksa lalu bayar lagi.", 'price_changed', ['prices' => [], 'unit_prices' => [], 'modifier_prices' => $priceChanges]);
        }

        if ($missingRequired !== null) {
            throw new PosException("Lengkapi pilihan {$missingRequired}.", 'modifier_required');
        }

        return ['lines' => $lines, 'flags' => array_values(array_unique($flags))];
    }

    /**
     * Grup aktif yang terpasang di tiap produk keranjang.
     *
     * @param  list<array<string, mixed>>  $items
     * @return Collection<int, Collection<int, ModifierGroup>>
     */
    private function attachedGroups(array $items): Collection
    {
        $productIds = collect($items)->pluck('product_id')->map(fn ($id) => (int) $id)->unique();
        $pivot = DB::table('modifier_group_product')->whereIn('product_id', $productIds)->get(['product_id', 'modifier_group_id']);
        $groups = ModifierGroup::query()->where('is_active', true)->whereIn('id', $pivot->pluck('modifier_group_id')->unique())->get()->keyBy('id');

        return $pivot->groupBy('product_id')->map(fn ($rows) => $rows->map(fn ($row) => $groups->get($row->modifier_group_id))->filter()->values());
    }

    /**
     * Grup pilihan tambahan sebuah produk untuk kasir web dan API (harga sudah final, tanpa pilihan nonaktif).
     *
     * @return list<array{id: int, name: string, min: int, max: int|null, rule: string, modifiers: list<array{id: int, name: string, price: int}>}>
     */
    public static function payload(Product $product): array
    {
        return $product->modifierGroups
            ->where('is_active', true)
            ->map(fn (ModifierGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'min' => $group->min_select,
                'max' => $group->max_select,
                'rule' => $group->ruleLabel(),
                'modifiers' => $group->modifiers->where('is_active', true)->map(fn (Modifier $modifier) => [
                    'id' => $modifier->id,
                    'name' => $modifier->name,
                    'price' => (int) $modifier->price,
                ])->values()->all(),
            ])
            ->filter(fn (array $group) => $group['modifiers'] !== [])
            ->values()
            ->all();
    }
}
