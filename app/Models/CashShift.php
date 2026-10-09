<?php

namespace App\Models;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CashShiftFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashShift extends Model
{
    use Auditable;
    use BelongsToOutlet;
    use BelongsToTenant;

    /** @use HasFactory<CashShiftFactory> */
    use HasFactory;

    protected $fillable = [
        'outlet_id',
        'number',
        'user_id',
        'opened_at',
        'opening_cash',
        'closed_at',
        'closed_by',
        'expected_cash',
        'counted_cash',
        'cash_difference',
        'closing_note',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * @return HasMany<SalePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /**
     * @return HasMany<CashMovement, $this>
     */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    /**
     * @param  Builder<CashShift>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * Rekap uang laci yang seharusnya ada: modal awal + tunai masuk (penjualan & pelunasan
     * piutang, sudah dikurangi kembalian) + kas masuk - kas keluar. Pembayaran milik penjualan
     * yang dibatalkan tidak dihitung, refund penjualan dari shift lain tercatat sebagai kas keluar.
     *
     * @return array{opening: int, cash_sales: int, cash_receivables: int, cash_in: int, cash_out: int, expected: int, sales_count: int, sales_total: int, voided_count: int, non_cash: array<string, int>}
     */
    public function summary(): array
    {
        $payments = $this->payments()
            ->whereHas('sale', fn (Builder $query) => $query->where('status', SaleStatus::Completed->value))
            ->selectRaw('kind, method, SUM(amount) as total')
            ->groupBy('kind', 'method')
            ->get();

        $cashSales = (int) $payments->where('kind', 'sale')->where('method', PaymentMethod::Cash->value)->sum('total');
        $cashReceivables = (int) $payments->where('kind', 'receivable')->where('method', PaymentMethod::Cash->value)->sum('total');

        $nonCash = [];
        foreach (PaymentMethod::cases() as $method) {
            if ($method !== PaymentMethod::Cash) {
                $nonCash[$method->value] = (int) $payments->where('method', $method->value)->sum('total');
            }
        }

        $movements = $this->cashMovements()->selectRaw('type, SUM(amount) as total')->groupBy('type')->pluck('total', 'type');
        $cashIn = (int) ($movements[CashMovementType::In->value] ?? 0);
        $cashOut = (int) ($movements[CashMovementType::Out->value] ?? 0);

        $completedSales = $this->sales()->where('status', SaleStatus::Completed->value);

        return [
            'opening' => (int) $this->opening_cash,
            'cash_sales' => $cashSales,
            'cash_receivables' => $cashReceivables,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'expected' => (int) $this->opening_cash + $cashSales + $cashReceivables + $cashIn - $cashOut,
            'sales_count' => (clone $completedSales)->count(),
            'sales_total' => (int) (clone $completedSales)->sum('total'),
            'voided_count' => $this->sales()->where('status', SaleStatus::Voided->value)->count(),
            'non_cash' => $nonCash,
        ];
    }

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'integer',
            'expected_cash' => 'integer',
            'counted_cash' => 'integer',
            'cash_difference' => 'integer',
        ];
    }
}
