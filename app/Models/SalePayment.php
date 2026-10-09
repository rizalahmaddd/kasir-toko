<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePayment extends Model
{
    use BelongsToOutlet;
    use BelongsToTenant;

    protected static function limitsToAccessibleOutlets(): bool
    {
        return false;
    }

    public const KIND_SALE = 'sale';

    public const KIND_RECEIVABLE = 'receivable';

    /** Uang muka pesanan yang sudah diterima sebelumnya, dipakai saat pelunasan (tanpa shift: uangnya sudah masuk laci saat DP). */
    public const KIND_DEPOSIT = 'deposit';

    protected $fillable = ['outlet_id', 'sale_id', 'cash_shift_id', 'user_id', 'kind', 'method', 'amount', 'reference', 'paid_at'];

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<CashShift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
    }

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
