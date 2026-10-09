<?php

namespace App\Models;

use App\Enums\StoreType;
use App\Models\Scopes\TenantScope;
use App\Support\SaasPlans;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    public const PLAN_FREE = 'free';

    public const PLAN_PRO = 'pro';

    public const PLAN_LIFETIME = 'lifetime';

    /**
     * Jeda minimal antar perubahan urutan outlet yang beroperasi saat jumlah outlet melebihi batas paket.
     */
    public const OUTLET_PRIORITY_COOLDOWN_DAYS = 30;

    protected $fillable = ['name', 'slug', 'status', 'plan', 'trial_ends_at', 'subscription_ends_at', 'store_type', 'onboarded_at', 'max_outlets_override', 'outlet_priority_changed_at'];

    /**
     * @return HasMany<Outlet, $this>
     */
    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<TenantSubscriptionLog, $this>
     */
    public function subscriptionLogs(): HasMany
    {
        return $this->hasMany(TenantSubscriptionLog::class);
    }

    /**
     * @return HasMany<SubscriptionInvoice, $this>
     */
    public function subscriptionInvoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    /**
     * Pemilik toko: akun superadmin pertama yang dibuat saat pendaftaran. Relasi roles() Spatie
     * memfilter per team aktif, jadi dari panel platform (tanpa tenant) pivot-nya dibaca langsung.
     */
    public function owner(): ?User
    {
        $ownerId = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.tenant_id', $this->id)
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('roles.name', 'superadmin')
            ->min('model_has_roles.model_id');

        return $ownerId ? User::withoutGlobalScopes()->find($ownerId) : null;
    }

    /**
     * Toko yang masa aktifnya (trial atau langganan, sesuai paketnya) berakhir di rentang ini.
     *
     * @param  Builder<Tenant>  $query
     */
    public function scopeAccessEndsBetween(Builder $query, Carbon $from, Carbon $until): void
    {
        $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $trial) => $trial->where('plan', self::PLAN_TRIAL)->whereBetween('trial_ends_at', [$from, $until]))
            ->orWhere(fn (Builder $paid) => $paid->where('plan', '!=', self::PLAN_TRIAL)->whereBetween('subscription_ends_at', [$from, $until])));
    }

    /**
     * @param  Builder<Tenant>  $query
     */
    public function scopeExpired(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $trial) => $trial->where('plan', self::PLAN_TRIAL)->where('trial_ends_at', '<', now()))
            ->orWhere(fn (Builder $paid) => $paid->where('plan', '!=', self::PLAN_TRIAL)->where('subscription_ends_at', '<', now())));
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

    public function daysLeftInTrial(): int
    {
        if (! $this->isOnTrial() || ! $this->trial_ends_at || $this->trial_ends_at->isPast()) {
            return 0;
        }

        return max(1, (int) ceil(now()->diffInHours($this->trial_ends_at) / 24));
    }

    public function isPro(): bool
    {
        if ($this->isOnTrial()) {
            return ! $this->hasExpired();
        }

        if ($this->plan === self::PLAN_LIFETIME) {
            return true;
        }

        if ($this->plan === self::PLAN_PRO) {
            return $this->subscription_ends_at === null || $this->subscription_ends_at->isFuture();
        }

        return false;
    }

    public function isLifetime(): bool
    {
        return $this->plan === self::PLAN_LIFETIME;
    }

    public function isFree(): bool
    {
        return ! $this->isPro();
    }

    /**
     * Akhir masa aktif: akhir uji coba untuk paket trial, akhir langganan untuk paket berbayar.
     * Null berarti tidak berbatas waktu.
     */
    public function accessEndsAt(): ?Carbon
    {
        if ($this->plan === self::PLAN_LIFETIME) {
            return null;
        }

        return $this->isOnTrial() ? $this->trial_ends_at : $this->subscription_ends_at;
    }

    public function hasExpired(): bool
    {
        if ($this->plan === self::PLAN_LIFETIME || $this->plan === self::PLAN_FREE) {
            return false;
        }

        return $this->accessEndsAt()?->isPast() ?? false;
    }

    public function graceDays(): int
    {
        return (int) (Setting::platform('saas.grace_days') ?? config('saas.grace_days', 0));
    }

    public function graceEndsAt(): ?Carbon
    {
        return $this->accessEndsAt()?->copy()->addDays($this->graceDays())->endOfDay();
    }

    /**
     * Masa tenggang setelah masa aktif lewat, sebelum toko di-hard-block total.
     */
    public function isInGracePeriod(): bool
    {
        return $this->hasExpired() && $this->graceDays() > 0 && ($this->graceEndsAt()?->isFuture() ?? false);
    }

    public function daysLeftInGrace(): int
    {
        if (! $this->isInGracePeriod()) {
            return 0;
        }

        return max(1, (int) ceil(now()->diffInHours($this->graceEndsAt()) / 24));
    }

    /**
     * Alasan toko tidak bisa dipakai, null kalau boleh dipakai. Dipakai sebagai `reason` di
     * respons API supaya aplikasi mobile bisa menampilkan layar yang sesuai.
     */
    public function blockedReason(): ?string
    {
        return match (true) {
            ! $this->isActive() => 'tenant_suspended',
            $this->plan === self::PLAN_FREE => null,
            $this->plan === self::PLAN_LIFETIME => null,
            $this->isInGracePeriod() => null,
            $this->hasExpired() => $this->isOnTrial() ? 'trial_expired' : 'subscription_expired',
            default => null,
        };
    }

    /**
     * Onboarding selesai saat pemilik menerapkan preset jenis toko atau melewatinya.
     */
    public function isOnboarded(): bool
    {
        return $this->onboarded_at !== null;
    }

    public function planLabel(): string
    {
        return SaasPlans::label($this->plan);
    }

    /**
     * Batas paket untuk "users", "products", atau "outlets"; null berarti tidak dibatasi. Outlet
     * selalu punya batas (minimal 1), dengan penimpaan per toko yang diatur admin platform.
     */
    public function limit(string $resource): ?int
    {
        if ($resource === 'outlets') {
            return $this->maxOutlets();
        }

        return SaasPlans::find($this->plan)["max_{$resource}"] ?? null;
    }

    public function maxOutlets(): int
    {
        return max(1, (int) ($this->max_outlets_override ?? SaasPlans::find($this->plan)['max_outlets'] ?? 1));
    }

    /**
     * Outlet aktif yang boleh bertransaksi: outlet utama dan prioritas teratas sebanyak batas paket.
     * Sisanya terkunci (hanya bisa dilihat) dan terbuka lagi otomatis saat batas naik.
     *
     * @return list<int>
     */
    public function operationalOutletIds(): array
    {
        return Outlet::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->id)
            ->active()
            ->byPriority()
            ->limit($this->maxOutlets())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Jenis usaha toko lalu jenis tiap outlet, tanpa duplikat. Atribut produk dan saran satuan
     * mengikuti gabungan ini karena katalog dipakai bersama semua outlet.
     *
     * @return list<StoreType>
     */
    public function storeTypes(): array
    {
        $outletTypes = Outlet::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->id)
            ->whereNotNull('store_type')
            ->byPriority()
            ->pluck('store_type')
            ->all();

        return collect([$this->store_type, ...$outletTypes])
            ->filter()
            ->unique(fn (StoreType $type) => $type->value)
            ->values()
            ->all();
    }

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'outlet_priority_changed_at' => 'datetime',
            'store_type' => StoreType::class,
        ];
    }
}
