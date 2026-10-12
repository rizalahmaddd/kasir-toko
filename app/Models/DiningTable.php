<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\DiningTableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Meja di satu outlet. `name` dipakai apa adanya sebagai table_label pesanan, jadi "05" di sini menjadi
 * "Meja 05" di open bill dan tiket dapur. `token` adalah isi QR meja; menggantinya mematikan QR lama.
 */
class DiningTable extends Model
{
    use Auditable;
    use BelongsToOutlet;
    use BelongsToTenant;

    /** @use HasFactory<DiningTableFactory> */
    use HasFactory;

    protected $fillable = ['outlet_id', 'name', 'area', 'capacity', 'token', 'is_active', 'sort_order'];

    /**
     * Token adalah isi QR meja; disembunyikan supaya tidak ikut tercatat di jejak audit.
     *
     * @var list<string>
     */
    protected $hidden = ['token'];

    protected static function booted(): void
    {
        static::creating(function (DiningTable $table) {
            $table->token ??= self::newToken();
        });
    }

    public static function newToken(): string
    {
        return Str::random(40);
    }

    /**
     * @return HasMany<SelfOrder, $this>
     */
    public function selfOrders(): HasMany
    {
        return $this->hasMany(SelfOrder::class);
    }

    public function orderUrl(): string
    {
        return route('self-order.table', $this->token);
    }

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
