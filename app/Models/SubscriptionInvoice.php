<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\SaasPlans;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tagihan langganan SaaS toko melalui payment gateway (SumoPod QRIS).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $invoice_number
 * @property string $plan
 * @property int $period_months
 * @property int $amount
 * @property string $status
 * @property string $payment_method
 * @property string|null $payment_gateway_ref
 * @property string|null $payment_url
 * @property Carbon|null $paid_at
 * @property Carbon|null $expires_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SubscriptionInvoice extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'invoice_number',
        'plan',
        'period_months',
        'amount',
        'status',
        'payment_method',
        'payment_gateway_ref',
        'payment_url',
        'paid_at',
        'expires_at',
        'metadata',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function planLabel(): string
    {
        return SaasPlans::label($this->plan);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PAID => 'Lunas',
            self::STATUS_PENDING => 'Menunggu Pembayaran',
            self::STATUS_EXPIRED => 'Kedaluwarsa',
            self::STATUS_FAILED => 'Gagal',
            self::STATUS_CANCELLED => 'Dibatalkan',
            default => ucfirst($this->status),
        };
    }

    public function statusBadgeColor(): string
    {
        return match ($this->status) {
            self::STATUS_PAID => 'emerald',
            self::STATUS_PENDING => 'amber',
            self::STATUS_EXPIRED, self::STATUS_FAILED, self::STATUS_CANCELLED => 'rose',
            default => 'slate',
        };
    }

    /**
     * @param  Builder<SubscriptionInvoice>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING);
    }

    /**
     * @param  Builder<SubscriptionInvoice>  $query
     */
    public function scopePaid(Builder $query): void
    {
        $query->where('status', self::STATUS_PAID);
    }

    /**
     * Otomatis membatalkan tagihan pending yang melampaui batas waktu (misal 3x24 jam).
     */
    public static function autoCancelStale(int $hours = 72): int
    {
        $cutoff = now()->subHours($hours);

        return static::query()
            ->where('status', self::STATUS_PENDING)
            ->where(function (Builder $query) use ($cutoff) {
                $query->where('created_at', '<=', $cutoff)
                    ->orWhere(function (Builder $sub) {
                        $sub->whereNotNull('expires_at')
                            ->where('expires_at', '<=', now());
                    });
            })
            ->update([
                'status' => self::STATUS_CANCELLED,
            ]);
    }

    /**
     * Nomor unik faktur langganan: INV-SUB-2026-XXXXX
     */
    public static function generateNumber(): string
    {
        $year = now()->year;
        $random = strtoupper(bin2hex(random_bytes(3))); // 6 karakter unik hex
        $time = now()->format('mdHis');

        return "INV-SUB-{$year}-{$time}-{$random}";
    }

    protected function casts(): array
    {
        return [
            'period_months' => 'integer',
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
