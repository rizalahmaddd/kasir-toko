<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Surat jalan untuk transaksi yang diantar. Isi barang dibaca dari transaksinya, jadi tidak disalin di sini.
 */
class DeliveryNote extends Model
{
    use Auditable;
    use BelongsToOutlet;
    use BelongsToTenant;

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    protected $fillable = ['outlet_id', 'sale_id', 'number', 'recipient', 'phone', 'address', 'project', 'driver', 'vehicle', 'notes', 'status', 'delivered_at', 'created_by'];

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }
}
