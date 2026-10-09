<?php

namespace App\Support;

use App\Models\Outlet;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pos\PosException;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Outlet yang sedang dilayani request ini, beserta outlet mana saja yang boleh dipakai user yang
 * login. Diisi App\Http\Middleware\IdentifyOutlet. Selama kosong (console, seeder) data diperlakukan
 * milik outlet utama toko dan tidak ada pembatasan akses.
 */
class CurrentOutlet
{
    private ?int $id = null;

    private ?Outlet $outlet = null;

    /**
     * Outlet yang ditugaskan ke user terbatas (aktif atau tidak). Null berarti user melihat semua outlet.
     *
     * @var list<int>|null
     */
    private ?array $restrictedTo = null;

    /**
     * Outlet aktif yang boleh dipilih user.
     *
     * @var list<int>
     */
    private array $accessible = [];

    private bool $accessLoaded = false;

    /** @var array{tenant: ?int, ids: list<int>, primary: ?int}|null */
    private ?array $tenantOutlets = null;

    public function id(): ?int
    {
        return $this->id;
    }

    /**
     * Outlet aktif, atau outlet utama toko kalau belum ada yang dipilih (seeder, job, console).
     */
    public function idOrPrimary(): ?int
    {
        return $this->id ?? $this->tenantOutlets()['primary'];
    }

    public function get(): ?Outlet
    {
        $id = $this->idOrPrimary();

        if ($id !== null && $this->outlet?->id !== $id) {
            $this->outlet = Outlet::query()->find($id);
        }

        return $this->outlet;
    }

    public function set(Outlet|int|null $outlet): void
    {
        $this->outlet = $outlet instanceof Outlet ? $outlet : null;
        $this->id = $outlet instanceof Outlet ? $outlet->id : $outlet;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(Outlet|int|null $outlet, Closure $callback): mixed
    {
        $previous = $this->id;
        $this->set($outlet);

        try {
            return $callback();
        } finally {
            $this->set($previous);
        }
    }

    /**
     * Dipanggil model Outlet setiap kali berubah supaya jumlah dan outlet utama dibaca ulang.
     */
    public function flush(): void
    {
        $this->tenantOutlets = null;
        $this->outlet = null;
    }

    /**
     * Toko dengan lebih dari satu outlet (aktif atau tidak): pemilih outlet, nama outlet di struk,
     * dan kode outlet di nomor dokumen hanya muncul untuk toko seperti ini.
     */
    /**
     * Channel realtime privat satu outlet; hanya pengguna yang boleh memakai outlet itu yang mendengarkan.
     */
    public static function channelFor(int $tenantId, int $outletId): string
    {
        return "tenant.{$tenantId}.outlet.{$outletId}";
    }

    public function channel(): ?string
    {
        $tenantId = app(CurrentTenant::class)->id();
        $outletId = $this->idOrPrimary();

        return $tenantId === null || $outletId === null ? null : self::channelFor($tenantId, $outletId);
    }

    public function isMultiOutlet(): bool
    {
        return count($this->tenantOutlets()['ids']) > 1;
    }

    /**
     * Dibaca sekali per request untuk user yang login, lalu dipakai OutletAccessScope dan pemilih outlet.
     */
    public function loadAccess(User $user): void
    {
        $outlets = Outlet::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $user->tenant_id)
            ->byPriority()
            ->pluck('is_active', 'id');
        $active = $outlets->filter()->keys()->map(fn ($id) => (int) $id)->all();

        // Toko dengan satu outlet tidak punya pembatasan: semua pengguna memakai outlet itu.
        if ($outlets->count() <= 1 || $user->hasAllOutletAccess()) {
            $this->restrictedTo = null;
            $this->accessible = $active;
        } else {
            $assigned = DB::table('outlet_user')->where('user_id', $user->id)->pluck('outlet_id')->map(fn ($id) => (int) $id)->all();
            $this->restrictedTo = $assigned;
            $this->accessible = array_values(array_intersect($assigned, $active));
        }

        $this->accessLoaded = true;
    }

    public function hasAccessLoaded(): bool
    {
        return $this->accessLoaded;
    }

    /**
     * Null: tidak dibatasi (pemilik, admin, toko satu outlet, atau tanpa user).
     *
     * @return list<int>|null
     */
    public function restrictedTo(): ?array
    {
        return $this->restrictedTo;
    }

    /**
     * @return list<int>
     */
    public function accessibleIds(): array
    {
        return $this->accessible;
    }

    public function canAccess(int $outletId): bool
    {
        return in_array($outletId, $this->accessible, true);
    }

    /**
     * Outlet yang boleh dipakai bertransaksi: dapat diakses dan tidak terkunci batas paket.
     *
     * @return list<int>
     */
    public function operationalIds(): array
    {
        $operational = $this->tenant()?->operationalOutletIds() ?? $this->tenantOutlets()['ids'];

        return array_values(array_intersect($this->accessible, $operational));
    }

    /**
     * Outlet awal bila klien tidak memilih: shift yang sedang terbuka, outlet terakhir dipakai, lalu
     * outlet utama. Outlet yang tidak terkunci didahulukan.
     *
     * @param  list<int|null>  $candidates
     */
    public function pickDefault(array $candidates): ?int
    {
        $operational = $this->operationalIds();
        $candidates = array_values(array_filter($candidates, fn ($id) => $id !== null && $this->canAccess((int) $id)));

        foreach ($candidates as $id) {
            if (in_array((int) $id, $operational, true)) {
                return (int) $id;
            }
        }

        return $operational[0] ?? $candidates[0] ?? $this->accessible[0] ?? null;
    }

    /**
     * Outlet aktif yang tidak terkunci batas paket di seluruh toko, tanpa memandang akses user.
     *
     * @return list<int>
     */
    public function tenantOperationalIds(): array
    {
        return $this->tenant()?->operationalOutletIds() ?? [];
    }

    /**
     * @throws PosException
     */
    public function ensureOperational(?int $outletId = null): void
    {
        $outletId ??= $this->idOrPrimary();
        $operational = $this->tenant()?->operationalOutletIds();

        if ($outletId === null || ($operational !== null && in_array($outletId, $operational, true))) {
            return;
        }

        $name = Outlet::query()->whereKey($outletId)->value('name') ?? 'ini';

        throw new PosException("Outlet {$name} sedang tidak aktif atau terkunci oleh batas paket. Hubungi pemilik toko.", 'outlet_locked');
    }

    /**
     * Dibaca langsung, bukan dari CurrentTenant::get(), supaya paket yang baru diubah selalu terbaca.
     */
    private function tenant(): ?Tenant
    {
        $tenantId = app(CurrentTenant::class)->id();

        return $tenantId === null ? null : Tenant::query()->find($tenantId);
    }

    /**
     * @return array{tenant: ?int, ids: list<int>, primary: ?int}
     */
    private function tenantOutlets(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        if ($this->tenantOutlets === null || $this->tenantOutlets['tenant'] !== $tenantId) {
            $rows = Outlet::query()->when($tenantId === null, fn ($query) => $query->withoutGlobalScope(TenantScope::class)->whereRaw('1 = 0'))
                ->orderByDesc('is_primary')->orderBy('id')->get(['id', 'is_primary']);

            $this->tenantOutlets = [
                'tenant' => $tenantId,
                'ids' => $rows->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'primary' => $rows->first()?->id,
            ];
        }

        return $this->tenantOutlets;
    }
}
