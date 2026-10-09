<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tiket dapur: daftar menu tanpa harga yang harus disiapkan. Item berisi snapshot nama, jumlah, satuan,
 * pilihan tambahan, dan catatan saat dikirim, jadi tidak berubah walau produk diedit.
 */
class KitchenTicket extends Model
{
    use BelongsToOutlet;
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DONE = 'done';

    protected $fillable = ['outlet_id', 'sale_id', 'user_id', 'label', 'order_type', 'items', 'status', 'done_at'];

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'done_at' => 'datetime',
        ];
    }
}
