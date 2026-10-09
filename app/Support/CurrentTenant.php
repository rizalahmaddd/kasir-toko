<?php

namespace App\Support;

use App\Models\Tenant;
use Closure;

/**
 * Tenant yang datanya sedang dilayani request/job ini. Diisi App\Http\Middleware\IdentifyTenant
 * dari user yang login dan ikut terbawa ke payload queue. Selama kosong (console, seeder, halaman
 * tamu) global scope tenant tidak memfilter apa pun.
 */
class CurrentTenant
{
    private ?int $id = null;

    private ?Tenant $tenant = null;

    public function id(): ?int
    {
        return $this->id;
    }

    public function get(): ?Tenant
    {
        if ($this->id !== null && $this->tenant?->id !== $this->id) {
            $this->tenant = Tenant::query()->find($this->id);
        }

        return $this->tenant;
    }

    public function set(Tenant|int|null $tenant): void
    {
        $this->tenant = $tenant instanceof Tenant ? $tenant : null;
        $this->id = $tenant instanceof Tenant ? $tenant->id : $tenant;

        // Peran Spatie disimpan per tenant (teams), dan daftar fitur yang dimatikan ditahan per request.
        setPermissionsTeamId($this->id);
        app()->forgetInstance(Features::DISABLED_KEY);
        app()->forgetInstance(Features::ENABLED_KEY);
    }

    /**
     * Folder unggahan milik toko aktif, mis. "tenants/7/products", supaya file tiap toko terpisah.
     */
    public function storagePath(string $directory): string
    {
        return $this->id === null ? $directory : "tenants/{$this->id}/{$directory}";
    }

    /**
     * Channel privat realtime milik toko aktif (dashboard, daftar produk, transaksi).
     */
    public function dashboardChannel(): ?string
    {
        return $this->id === null ? null : "tenant.{$this->id}.dashboard";
    }

    /**
     * Null menjalankan callback di level platform (tanpa filter tenant).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(Tenant|int|null $tenant, Closure $callback): mixed
    {
        $previous = $this->id;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->set($previous);
        }
    }
}
