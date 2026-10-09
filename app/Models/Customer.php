<?php

namespace App\Models;

use App\Events\CustomerChanged;
use App\Listeners\NotifyOfNewCustomer;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\NumberFormatter;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'type',
        'contact_person',
        'phone',
        'email',
        'address',
        'npwp',
        'payment_term_days',
        'credit_limit',
        'is_active',
    ];

    /**
     * Lewat event model supaya perubahan dari halaman web maupun API sama-sama tersiar.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'saved' => CustomerChanged::class,
        'deleted' => CustomerChanged::class,
    ];

    protected static function booted(): void
    {
        static::created(fn (Customer $customer) => app(NotifyOfNewCustomer::class)->handle($customer));
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function outstandingBalance(): int
    {
        return (int) $this->sales()->completed()->sum('due_amount');
    }

    /**
     * Deleting a customer who still owes money would leave the debt with nobody to collect from.
     */
    public function deletionBlockedReason(): ?string
    {
        $due = $this->outstandingBalance();

        return $due > 0
            ? 'Pelanggan ini masih punya kasbon '.NumberFormatter::currency($due).' yang belum lunas. Lunasi dulu sebelum menghapus.'
            : null;
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'credit_limit' => 'integer',
        ];
    }
}
