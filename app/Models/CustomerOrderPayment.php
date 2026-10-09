<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uang muka (amount positif) atau pengembalian DP saat pesanan dibatalkan (kind refund, amount negatif).
 * DP tunai juga dicatat sebagai kas masuk di shift penerimanya supaya laci tetap cocok.
 */
class CustomerOrderPayment extends Model
{
    use BelongsToTenant;

    public const KIND_DEPOSIT = 'deposit';

    public const KIND_REFUND = 'refund';

    protected $fillable = ['customer_order_id', 'outlet_id', 'cash_shift_id', 'user_id', 'kind', 'method', 'amount', 'reference', 'paid_at'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }
}
