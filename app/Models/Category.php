<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $fillable = ['name', 'sort_order', 'is_active'];

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Outlet yang menjual kategori ini. Tanpa baris berarti tersedia di semua outlet.
     *
     * @return BelongsToMany<Outlet, $this>
     */
    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'category_outlet');
    }

    /**
     * Daftar kosong mengembalikan kategori ke semua outlet.
     *
     * @param  list<int>  $outletIds
     */
    public function restrictToOutlets(array $outletIds): void
    {
        $this->outlets()->sync(collect($outletIds)->unique()->mapWithKeys(fn (int $id) => [$id => ['tenant_id' => $this->tenant_id]])->all());
    }

    /**
     * Versi restrictToOutlets() untuk pengguna yang hanya memegang sebagian outlet ($manageableIds;
     * null = semua). Ketersediaan di outlet lain dipertahankan apa adanya, jadi kategori "semua outlet"
     * tidak bisa hilang dari outlet yang tidak dipegang pengguna ini.
     *
     * @param  list<int>  $outletIds
     * @param  list<int>|null  $manageableIds
     */
    public function restrictToOutletsWithin(array $outletIds, ?array $manageableIds): void
    {
        if ($manageableIds === null) {
            $this->restrictToOutlets($outletIds);

            return;
        }

        $current = $this->outlets()->pluck('outlets.id')->map(fn ($id) => (int) $id)->all();

        if ($current === [] && $outletIds === []) {
            return;
        }

        $allIds = Outlet::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $result = array_values(array_unique([
            ...array_diff($current === [] ? $allIds : $current, $manageableIds),
            ...array_intersect($outletIds, $manageableIds),
        ]));

        $this->restrictToOutlets(array_diff($allIds, $result) === [] ? [] : $result);
    }

    public function isAvailableAt(int $outletId): bool
    {
        $outletIds = $this->outlets()->pluck('outlets.id');

        return $outletIds->isEmpty() || $outletIds->contains($outletId);
    }

    /**
     * @param  Builder<Category>  $query
     */
    public function scopeAvailableAt(Builder $query, int $outletId): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereDoesntHave('outlets')
            ->orWhereHas('outlets', fn (Builder $outlets) => $outlets->whereKey($outletId)));
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
