<?php

namespace App\Models;

use App\Enums\CashMovementType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CashMovement extends Model
{
    use Auditable;
    use BelongsToTenant;

    protected $fillable = ['cash_shift_id', 'user_id', 'type', 'amount', 'reason', 'reference_type', 'reference_id'];

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

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'amount' => 'integer',
        ];
    }
}
