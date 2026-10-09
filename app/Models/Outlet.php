<?php

namespace App\Models;

use App\Enums\StoreType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\CurrentOutlet;
use App\Support\OutletFeatures;
use Database\Factories\OutletFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cabang di dalam satu toko. Produk, kategori, dan pelanggan dipakai bersama; stok, shift, transaksi,
 * dan transaksi tertunda dicatat per outlet. Outlet yang pernah dipakai bertransaksi tidak bisa dihapus.
 */
class Outlet extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<OutletFactory> */
    use HasFactory;

    public const DEFAULT_CODE = 'PST';

    protected $fillable = ['name', 'code', 'address', 'phone', 'is_primary', 'is_active', 'priority', 'store_type', 'capabilities', 'disabled_features'];

    protected static function booted(): void
    {
        static::saved(function () {
            app(CurrentOutlet::class)->flush();
            OutletFeatures::flush();
        });
        static::deleted(function () {
            app(CurrentOutlet::class)->flush();
            OutletFeatures::flush();
        });
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'outlet_user')->withoutGlobalScopes();
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_outlet');
    }

    /**
     * Jenis usaha outlet; outlet yang belum diberi jenis sendiri mengikuti jenis toko.
     */
    public function effectiveStoreType(): ?StoreType
    {
        return $this->store_type ?? $this->tenant?->store_type;
    }

    /**
     * @return HasMany<ProductStock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    /**
     * @return HasMany<OutletSetting, $this>
     */
    public function settings(): HasMany
    {
        return $this->hasMany(OutletSetting::class);
    }

    /**
     * @param  Builder<Outlet>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Urutan siapa yang tetap bisa beroperasi saat batas paket lebih kecil dari jumlah outlet.
     *
     * @param  Builder<Outlet>  $query
     */
    public function scopeByPriority(Builder $query): void
    {
        $query->orderByDesc('is_primary')->orderBy('priority')->orderBy('id');
    }

    /**
     * Aktif dan tidak terkunci batas paket. Outlet terkunci masih bisa dilihat datanya.
     */
    public function isOperational(): bool
    {
        return $this->is_active && in_array($this->id, $this->tenant?->operationalOutletIds() ?? [], true);
    }

    public function isLockedByPlan(): bool
    {
        return $this->is_active && ! $this->isOperational();
    }

    /**
     * Pernah dipakai bertransaksi atau mencatat stok; kode dan penghapusan dikunci setelahnya.
     */
    public function hasHistory(): bool
    {
        return Sale::query()->withoutGlobalScopes()->where('outlet_id', $this->id)->exists()
            || CashShift::query()->withoutGlobalScopes()->where('outlet_id', $this->id)->exists()
            || StockMovement::query()->withoutGlobalScopes()->where('outlet_id', $this->id)->exists()
            || StockTransfer::query()->where(fn (Builder $query) => $query->where('from_outlet_id', $this->id)->orWhere('to_outlet_id', $this->id))->exists()
            || StockCount::query()->withoutGlobalScopes()->where('outlet_id', $this->id)->exists();
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'store_type' => StoreType::class,
            'capabilities' => 'array',
            'disabled_features' => 'array',
        ];
    }
}
