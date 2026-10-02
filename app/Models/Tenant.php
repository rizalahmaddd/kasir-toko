<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Satu toko pelanggan SaaS. Semua data bisnis (produk, transaksi, pengguna, pengaturan) menempel
 * ke tenant lewat kolom tenant_id dan hanya terlihat oleh pengguna tenant yang sama.
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const PLAN_TRIAL = 'trial';

    protected $fillable = ['name', 'slug', 'status', 'plan', 'trial_ends_at', 'subscription_ends_at'];

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'toko';
        $slug = $base;

        for ($i = 2; static::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isOnTrial(): bool
    {
        return $this->plan === self::PLAN_TRIAL;
    }

    /**
     * Akhir masa aktif: akhir uji coba untuk paket trial, akhir langganan untuk paket berbayar.
     * Null berarti tidak berbatas waktu.
     */
    public function accessEndsAt(): ?Carbon
    {
        return $this->isOnTrial() ? $this->trial_ends_at : $this->subscription_ends_at;
    }

    public function hasExpired(): bool
    {
        return $this->accessEndsAt()?->isPast() ?? false;
    }

    /**
     * Alasan toko tidak bisa dipakai, null kalau boleh dipakai. Dipakai sebagai `reason` di
     * respons API supaya aplikasi mobile bisa menampilkan layar yang sesuai.
     */
    public function blockedReason(): ?string
    {
        return match (true) {
            ! $this->isActive() => 'tenant_suspended',
            $this->hasExpired() => $this->isOnTrial() ? 'trial_expired' : 'subscription_expired',
            default => null,
        };
    }

    public function planLabel(): string
    {
        return config("saas.plans.{$this->plan}.label", Str::title($this->plan));
    }

    /**
     * Batas paket untuk "users" atau "products"; null berarti tidak dibatasi.
     */
    public function limit(string $resource): ?int
    {
        return config("saas.plans.{$this->plan}.max_{$resource}");
    }

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
        ];
    }
}
