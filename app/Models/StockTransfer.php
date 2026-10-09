<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\CurrentOutlet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    use Auditable;
    use BelongsToTenant;

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['number', 'from_outlet_id', 'to_outlet_id', 'status', 'created_by', 'note', 'transferred_at', 'cancelled_at', 'cancelled_by'];

    /**
     * Pengguna yang ditugaskan ke outlet tertentu hanya melihat transfer yang melibatkan outlet itu.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('outlet-access', function (Builder $query) {
            $restricted = app(CurrentOutlet::class)->restrictedTo();

            if ($restricted !== null) {
                $query->where(fn (Builder $query) => $query->whereIn('from_outlet_id', $restricted)->orWhereIn('to_outlet_id', $restricted));
            }
        });
    }

    /**
     * @return HasMany<StockTransferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function fromOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'from_outlet_id');
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function toOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'to_outlet_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    protected function casts(): array
    {
        return [
            'transferred_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
