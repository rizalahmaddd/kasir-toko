<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountSerial extends Model
{
    use BelongsToTenant;

    public const RESULT_MATCHED = 'matched';

    public const RESULT_UNKNOWN = 'unknown';

    public const RESULT_OTHER_OUTLET = 'other_outlet';

    public const RESULT_SOLD = 'sold';

    public const RESULT_REMOVED = 'removed';

    public const ACTION_REGISTER = 'register';

    public const ACTION_RELOCATE = 'relocate';

    public const ACTION_IGNORE = 'ignore';

    protected $fillable = ['stock_count_id', 'product_id', 'serial', 'result', 'action', 'user_id', 'scanned_at'];

    /**
     * @return BelongsTo<StockCount, $this>
     */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
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
            'scanned_at' => 'datetime',
        ];
    }
}
