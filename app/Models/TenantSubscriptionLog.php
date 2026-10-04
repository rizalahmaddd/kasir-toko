<?php

namespace App\Models;

use Database\Factories\TenantSubscriptionLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat perubahan langganan toko oleh admin platform: perpanjangan, ganti paket, dan
 * suspend/aktifkan, beserta nominal pembayaran manual kalau ada. Data milik platform, jadi
 * sengaja tidak memakai scope tenant.
 */
class TenantSubscriptionLog extends Model
{
    /** @use HasFactory<TenantSubscriptionLogFactory> */
    use HasFactory;

    public const ACTION_EXTEND = 'extend';

    public const ACTION_UPDATE = 'update';

    public const ACTION_STATUS = 'status';

    protected $fillable = [
        'tenant_id', 'user_id', 'action',
        'from_plan', 'to_plan', 'from_status', 'to_status', 'from_ends_at', 'to_ends_at',
        'amount', 'note',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_EXTEND => 'Perpanjangan',
            self::ACTION_STATUS => $this->to_status === Tenant::STATUS_SUSPENDED ? 'Dinonaktifkan' : 'Diaktifkan',
            default => $this->from_plan !== $this->to_plan ? 'Ganti paket' : 'Perubahan data',
        };
    }

    protected function casts(): array
    {
        return [
            'from_ends_at' => 'datetime',
            'to_ends_at' => 'datetime',
            'amount' => 'integer',
        ];
    }
}
