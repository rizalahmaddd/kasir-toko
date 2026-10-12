<?php

namespace App\Models;

use App\Enums\OrderType;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SelfOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pesanan yang dibuat pelanggan dari QR. Hanya kotak masuk kasir dan sumber pelacakan status: tagihannya
 * tetap lewat open bill (HeldOrder) atau langsung menjadi Sale. Pemenuhan (`status`) dan pembayaran
 * (`payment_status`) sengaja dua kolom, karena makan di tempat bisa siap disajikan sebelum dibayar.
 */
class SelfOrder extends Model
{
    use BelongsToOutlet;
    use BelongsToTenant;

    /** @use HasFactory<SelfOrderFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_KITCHEN = 'in_kitchen';

    public const STATUS_READY = 'ready';

    public const STATUS_SERVED = 'served';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_IN_KITCHEN, self::STATUS_READY];

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_PENDING_VERIFICATION = 'pending_verification';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_REJECTED = 'rejected';

    public const METHOD_CASHIER = 'cashier';

    public const METHOD_QRIS = 'qris';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Menunggu konfirmasi',
        self::STATUS_IN_KITCHEN => 'Sedang disiapkan',
        self::STATUS_READY => 'Siap',
        self::STATUS_SERVED => 'Selesai',
        self::STATUS_CANCELLED => 'Dibatalkan',
        self::STATUS_EXPIRED => 'Kedaluwarsa',
    ];

    public const PAYMENT_LABELS = [
        self::PAYMENT_UNPAID => 'Belum dibayar',
        self::PAYMENT_PENDING_VERIFICATION => 'Menunggu verifikasi',
        self::PAYMENT_PAID => 'Lunas',
        self::PAYMENT_REJECTED => 'Pembayaran ditolak',
    ];

    protected $fillable = [
        'outlet_id', 'dining_table_id', 'held_order_id', 'sale_id', 'number', 'client_uuid', 'session_token', 'device_id',
        'order_type', 'table_label', 'queue_number', 'customer_name', 'customer_phone', 'payment_method', 'payment_status',
        'status', 'subtotal', 'service_charge_rate', 'service_charge_amount', 'tax_rate', 'tax_amount', 'total',
        'kitchen_sent', 'notes', 'accepted_at', 'ready_at', 'served_at', 'paid_at', 'cancelled_at', 'cancel_reason',
    ];

    protected $hidden = ['session_token', 'device_id'];

    /**
     * @return HasMany<SelfOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SelfOrderItem::class);
    }

    /**
     * @return BelongsTo<DiningTable, $this>
     */
    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class);
    }

    /**
     * @return BelongsTo<HeldOrder, $this>
     */
    public function heldOrder(): BelongsTo
    {
        return $this->belongsTo(HeldOrder::class);
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return HasMany<KitchenTicket, $this>
     */
    public function kitchenTickets(): HasMany
    {
        return $this->hasMany(KitchenTicket::class);
    }

    /**
     * Masih perlu tindakan kasir, dapur, atau pelayan.
     *
     * @param  Builder<SelfOrder>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', self::OPEN_STATUSES);
    }

    /**
     * Masih bisa ditagih di kasir: belum lunas dan tidak dibatalkan. Pesanan makan di tempat yang sudah
     * diantar (served) tetap harus bisa dibayar dari open bill-nya.
     */
    public function isPayable(): bool
    {
        return ! $this->isPaid() && $this->sale_id === null && ! in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_EXPIRED], true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_PAID;
    }

    public function isQris(): bool
    {
        return $this->payment_method === self::METHOD_QRIS;
    }

    public function isDineIn(): bool
    {
        return $this->order_type === OrderType::DineIn->value;
    }

    /**
     * Label di kotak masuk, open bill, dan tiket dapur: meja untuk makan di tempat, nomor antrean untuk bawa pulang.
     */
    public function label(): string
    {
        return filled($this->table_label) ? "Meja {$this->table_label}" : 'Antrean #'.($this->queue_number ?? '-');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function paymentLabel(): string
    {
        return self::PAYMENT_LABELS[$this->payment_status] ?? $this->payment_status;
    }

    protected function casts(): array
    {
        return [
            'queue_number' => 'integer',
            'subtotal' => 'integer',
            'service_charge_rate' => 'float',
            'service_charge_amount' => 'integer',
            'tax_rate' => 'float',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'kitchen_sent' => 'array',
            'accepted_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
