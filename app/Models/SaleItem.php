<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'sale_id',
        'product_id',
        'product_name',
        'sku',
        'unit',
        'quantity',
        'price',
        'cost_price',
        'discount_amount',
        'total',
        'note',
        'product_unit_id',
        'unit_factor',
        'base_quantity',
        'prescription_item_id',
        'modifiers',
        'modifiers_total',
        'auto_discount',
    ];

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return HasMany<SaleItemBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(SaleItemBatch::class);
    }

    /**
     * @return HasMany<ProductSerial, $this>
     */
    public function serials(): HasMany
    {
        return $this->hasMany(ProductSerial::class);
    }

    /**
     * @return HasMany<SaleItemComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(SaleItemComponent::class);
    }

    /**
     * Nama pilihan tambahan yang dipilih, mis. "Large, Less Sugar".
     */
    public function modifierSummary(): ?string
    {
        $names = array_filter(array_map(fn ($modifier) => is_array($modifier) ? ($modifier['name'] ?? null) : null, $this->modifiers ?? []));

        return $names === [] ? null : implode(', ', $names);
    }

    /**
     * @return BelongsTo<PrescriptionItem, $this>
     */
    public function prescriptionItem(): BelongsTo
    {
        return $this->belongsTo(PrescriptionItem::class);
    }

    /**
     * Jumlah dalam satuan dasar produk; transaksi sebelum multi-satuan tidak menyimpannya.
     */
    public function baseQuantity(): float
    {
        return (float) ($this->base_quantity ?? round((float) $this->quantity * (float) ($this->unit_factor ?: 1), 3));
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_factor' => 'decimal:3',
            'base_quantity' => 'decimal:3',
            'price' => 'integer',
            'cost_price' => 'integer',
            'discount_amount' => 'integer',
            'total' => 'integer',
            'modifiers' => 'array',
            'modifiers_total' => 'integer',
            'auto_discount' => 'integer',
        ];
    }
}
