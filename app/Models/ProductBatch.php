<?php

namespace App\Models;

use App\Enums\BatchSource;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stok satu produk di satu outlet per nomor batch & tanggal kedaluwarsa. Jumlah quantity semua batch
 * (produk, outlet) harus sama dengan product_stocks.stock; hanya App\Services\Pos\BatchService yang menulisnya.
 */
class ProductBatch extends Model
{
    use BelongsToTenant;

    protected $fillable = ['product_id', 'outlet_id', 'batch_number', 'expires_at', 'quantity', 'unit_cost', 'received_at', 'source'];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(today());
    }

    public function label(): string
    {
        $number = $this->batch_number ?: 'Tanpa nomor';

        return $this->expires_at ? "{$number} · ED {$this->expires_at->translatedFormat('d M Y')}" : $number;
    }

    /**
     * Urutan FEFO: kedaluwarsa paling awal dulu, batch tanpa tanggal paling akhir.
     *
     * @param  Builder<ProductBatch>  $query
     */
    public function scopeFefo(Builder $query): void
    {
        $query->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderBy('received_at')->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'received_at' => 'datetime',
            'quantity' => 'decimal:3',
            'unit_cost' => 'integer',
            'source' => BatchSource::class,
        ];
    }
}
