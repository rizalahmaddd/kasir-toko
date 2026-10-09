<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jumlah dalam satuan dasar produk. quantity_prescribed sudah termasuk iter yang diizinkan dokter.
 */
class PrescriptionItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['prescription_id', 'product_id', 'product_name', 'quantity_prescribed', 'quantity_dispensed', 'iteration', 'dosage_instructions'];

    /**
     * @return BelongsTo<Prescription, $this>
     */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function remaining(): float
    {
        return max(0.0, round((float) $this->quantity_prescribed - (float) $this->quantity_dispensed, 3));
    }

    protected function casts(): array
    {
        return [
            'quantity_prescribed' => 'decimal:3',
            'quantity_dispensed' => 'decimal:3',
            'iteration' => 'integer',
        ];
    }
}
