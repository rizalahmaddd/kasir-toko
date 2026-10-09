<?php

namespace App\Models;

use App\Enums\OrderType;
use App\Enums\SaleStatus;
use App\Events\SaleRecorded;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use Auditable;
    use BelongsToOutlet;
    use BelongsToTenant;

    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    protected $fillable = [
        'outlet_id',
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
        'prescription_id',
        'flags',
        'order_type',
        'table_label',
        'queue_number',
        'service_charge_rate',
        'service_charge_amount',
        'customer_order_id',
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
     * @return BelongsTo<Prescription, $this>
     */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /**
     * @return HasMany<KitchenTicket, $this>
     */
    public function kitchenTickets(): HasMany
    {
        return $this->hasMany(KitchenTicket::class);
    }

    /**
     * @return BelongsTo<CustomerOrder, $this>
     */
    public function customerOrder(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class);
    }

    /**
     * @return HasMany<DeliveryNote, $this>
     */
    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(DeliveryNote::class);
    }

    public function orderType(): ?OrderType
    {
        return OrderType::tryFrom((string) $this->order_type);
    }

    /**
     * Penanda pesanan untuk struk dan tiket dapur, mis. "Meja 5" atau "Antrean 012".
     */
    public function orderLabel(): ?string
    {
        return match (true) {
            filled($this->table_label) => "Meja {$this->table_label}",
            $this->queue_number !== null => 'Antrean '.str_pad((string) $this->queue_number, 3, '0', STR_PAD_LEFT),
            default => null,
        };
    }

    public const FLAG_LABELS = [
        'prescription_unverified' => 'Resep belum diverifikasi',
        'controlled_drug_sold' => 'Obat narkotika/psikotropika terjual',
        'expired_batch_sold' => 'Batch kedaluwarsa terjual',
        'outlet_locked_sync' => 'Disinkron saat outlet terkunci',
        'modifier_snapshot' => 'Pilihan tambahan sudah berubah/dihapus saat disinkron',
        'serial_unverified' => 'Nomor seri tidak ditemukan di stok saat disinkron',
        'credit_limit_exceeded' => 'Kasbon melewati batas pelanggan (dari antrean offline)',
        'not_sold_at_outlet' => 'Produk yang tidak dijual di outlet ini terjual (dari antrean offline)',
    ];

    /**
     * Catatan transaksi yang perlu ditinjau, mis. transaksi offline yang lolos aturan resep atau kedaluwarsa.
     *
     * @return list<string>
     */
    public function flagLabels(): array
    {
        return array_map(fn (string $flag) => self::FLAG_LABELS[$flag] ?? $flag, $this->flags ?? []);
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
            'flags' => 'array',
            'queue_number' => 'integer',
            'service_charge_rate' => 'decimal:2',
            'service_charge_amount' => 'integer',
        ];
    }
}
