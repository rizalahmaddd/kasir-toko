<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satuan jual tambahan sebuah produk. Stok selalu dicatat dalam satuan dasar (products.unit);
 * satu satuan ini setara $factor satuan dasar.
 */
class ProductUnit extends Model
{
    use Auditable;
    use BelongsToTenant;
    use SoftDeletes;

    protected $fillable = ['product_id', 'name', 'factor', 'price', 'barcode', 'is_default_sale', 'sort_order'];

    protected static function booted(): void
    {
        static::softDeleted(function (ProductUnit $unit) {
            if ($unit->barcode !== null) {
                $unit->forceFill(['barcode' => null])->saveQuietly();
            }
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * Harga jual satuan ini: harga tetap kalau diisi, selain itu harga satuan dasar (sudah per outlet) dikali faktor.
     */
    public function priceFrom(int $basePrice): int
    {
        return $this->price !== null ? (int) $this->price : (int) round($basePrice * (float) $this->factor);
    }

    protected function casts(): array
    {
        return [
            'factor' => 'decimal:3',
            'price' => 'integer',
            'is_default_sale' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
