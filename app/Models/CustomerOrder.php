<?php

namespace App\Models;

use App\Enums\CustomerOrderStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pesanan dengan tanggal ambil (mis. kue ulang tahun) atau tiket servis. Uang muka dicatat di
 * customer_order_payments; pelunasan dilakukan lewat checkout kasir yang membawa customer_order_id.
 */
class CustomerOrder extends Model
{
    use Auditable;
    use BelongsToOutlet;
    use BelongsToTenant;

    public const TYPE_ORDER = 'order';

    public const TYPE_SERVICE = 'service';

    protected $fillable = [
        'outlet_id', 'number', 'type', 'status', 'customer_id', 'customer_name', 'customer_phone', 'pickup_at',
        'estimated_total', 'deposit', 'device', 'device_serial', 'complaint', 'notes', 'image_path', 'sale_id',
        'created_by', 'completed_at', 'cancelled_at',
    ];

    /**
     * @return HasMany<CustomerOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CustomerOrderItem::class);
    }

    /**
     * @return HasMany<CustomerOrderPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(CustomerOrderPayment::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<CustomerOrder>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status', [CustomerOrderStatus::PickedUp->value, CustomerOrderStatus::Cancelled->value]);
    }

    public function isService(): bool
    {
        return $this->type === self::TYPE_SERVICE;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function remaining(): int
    {
        return max(0, (int) $this->estimated_total - (int) $this->deposit);
    }

    protected function casts(): array
    {
        return [
            'status' => CustomerOrderStatus::class,
            'pickup_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'estimated_total' => 'integer',
            'deposit' => 'integer',
        ];
    }
}
