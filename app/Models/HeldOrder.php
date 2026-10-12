<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HeldOrder extends Model
{
    use BelongsToOutlet;
    use BelongsToTenant;

    protected $fillable = ['outlet_id', 'user_id', 'customer_id', 'label', 'cart', 'item_count', 'total', 'order_type', 'table_label', 'self_order_id'];

    /**
     * @return BelongsTo<SelfOrder, $this>
     */
    public function selfOrder(): BelongsTo
    {
        return $this->belongsTo(SelfOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected function casts(): array
    {
        return [
            'cart' => 'array',
            'item_count' => 'integer',
            'total' => 'integer',
        ];
    }
}
