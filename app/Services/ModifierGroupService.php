<?php

namespace App\Services;

use App\Models\ModifierGroup;
use App\Support\TenantRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Simpan grup pilihan tambahan beserta pilihannya dan produk yang memakainya; dipakai halaman web dan API.
 * Pilihan yang tidak dikirim lagi di-soft delete supaya transaksi tertunda & antrean offline tetap tercatat.
 */
class ModifierGroupService
{
    /**
     * @param  int|null  $ignoreId  id grup yang sedang diubah (untuk cek nama unik)
     * @return array<string, list<mixed>>
     */
    public static function rules(?int $ignoreId = null, string $prefix = ''): array
    {
        return [
            "{$prefix}name" => ['required', 'string', 'max:60', TenantRule::unique('modifier_groups', 'name')->ignore($ignoreId)->whereNull('deleted_at')],
            "{$prefix}min_select" => ['required', 'integer', 'min:0', 'max:20'],
            "{$prefix}max_select" => ['nullable', 'integer', 'min:1', 'max:20'],
            "{$prefix}is_active" => ['boolean'],
            'options' => ['array', 'max:30'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.name' => ['required', 'string', 'max:60'],
            'options.*.price' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'options.*.product_id' => ['nullable', TenantRule::exists('products', 'id')],
            'options.*.ingredient_quantity' => ['nullable', 'required_with:options.*.product_id', 'numeric', 'gt:0', 'max:99999'],
            'options.*.is_active' => ['boolean'],
            'product_ids' => ['nullable', 'array', 'max:500'],
            'product_ids.*' => ['integer', TenantRule::exists('products', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'name.unique' => 'Nama grup ini sudah dipakai.',
            'options.*.name.required' => 'Nama pilihan wajib diisi.',
            'options.*.ingredient_quantity.required_with' => 'Isi jumlah bahan yang terpakai per porsi.',
        ];
    }

    /**
     * @param  array{name: string, min_select: int|string, max_select?: int|string|null, is_active?: bool, options: list<array<string, mixed>>, product_ids?: list<int|string>|null}  $data
     *
     * @throws ValidationException
     */
    public function save(?ModifierGroup $group, array $data): ModifierGroup
    {
        $min = (int) $data['min_select'];
        $max = ($data['max_select'] ?? null) === null || $data['max_select'] === '' ? null : (int) $data['max_select'];

        if ($max !== null && $max < max(1, $min)) {
            throw ValidationException::withMessages(['max_select' => 'Maksimal pilihan tidak boleh lebih kecil dari minimal (dan minimal 1).']);
        }

        if (($data['options'] ?? []) === []) {
            throw ValidationException::withMessages(['options' => 'Tambahkan minimal satu pilihan.']);
        }

        return DB::transaction(function () use ($group, $data, $min, $max) {
            $group ??= new ModifierGroup;
            $group->fill(['name' => trim($data['name']), 'min_select' => $min, 'max_select' => $max, 'is_active' => (bool) ($data['is_active'] ?? true)]);
            $group->save();

            $existing = $group->modifiers()->get()->keyBy('id');
            $keep = [];

            foreach (array_values($data['options']) as $order => $row) {
                $ingredient = filled($row['product_id'] ?? null) ? (int) $row['product_id'] : null;
                $attributes = [
                    'name' => trim((string) $row['name']),
                    'price' => (int) ($row['price'] ?: 0),
                    'product_id' => $ingredient,
                    'ingredient_quantity' => $ingredient !== null && filled($row['ingredient_quantity'] ?? null) ? round((float) $row['ingredient_quantity'], 3) : null,
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'sort_order' => $order + 1,
                ];

                $modifier = isset($row['id']) ? $existing->get((int) $row['id']) : null;
                $modifier ? $modifier->update($attributes) : $modifier = $group->modifiers()->create($attributes);
                $keep[] = $modifier->id;
            }

            $existing->except($keep)->each->delete();

            if (array_key_exists('product_ids', $data) && $data['product_ids'] !== null) {
                $group->products()->sync(collect($data['product_ids'])->map(fn ($id) => (int) $id)->unique()->mapWithKeys(fn (int $id) => [$id => ['sort_order' => 0]])->all());
            }

            return $group->refresh();
        });
    }
}
