<?php

namespace App\Models;

use App\Enums\SaleStatus;
use App\Events\SaleRecorded;
use App\Models\Concerns\Auditable;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use Auditable;

    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    protected $fillable = [
        'number',
        'client_uuid',
        'cash_shift_id',
        'user_id',
        'customer_id',
        'status',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'total',
        'paid_amount',
        'cash_received',
        'change_amount',
        'due_amount',
        'note',
        'sold_at',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'saved' => SaleRecorded::class,
    ];

    /**
     * @return HasMany<SaleItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * @return HasMany<SalePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<CashShift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
    }

    /**
     * @param  Builder<Sale>  $query
     */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', SaleStatus::Completed->value);
    }

    public function isVoided(): bool
    {
        return $this->status === SaleStatus::Voided;
    }

    public function itemCount(): float
    {
        return (float) $this->items->sum('quantity');
    }

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'subtotal' => 'integer',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'integer',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'paid_amount' => 'integer',
            'cash_received' => 'integer',
            'change_amount' => 'integer',
            'due_amount' => 'integer',
            'sold_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
