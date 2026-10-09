<?php

namespace App\Support;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Batas jumlah data per paket langganan (config/saas.php). Dicek sebelum data baru dibuat;
 * data yang sudah ada tidak disentuh saat toko turun paket.
 */
class PlanLimits
{
    private const LABELS = ['users' => 'pengguna', 'products' => 'produk', 'outlets' => 'outlet'];

    /**
     * Baris tenant dikunci kalau pemanggil sudah di dalam transaksi, supaya dua request bersamaan
     * tidak sama-sama lolos saat sisa kuota tinggal satu.
     *
     * @throws ValidationException
     */
    public static function ensureCanAdd(string $resource, string $errorKey): void
    {
        $tenantId = app(CurrentTenant::class)->id();
        $tenant = $tenantId === null ? null : Tenant::query()->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->find($tenantId);

        $limit = $tenant?->limit($resource);

        if ($limit === null) {
            return;
        }

        if (self::count($resource) >= $limit) {
            $suffix = $resource === 'outlets' ? ' Naik ke paket Pro untuk menambah outlet.' : ' Hubungi admin layanan untuk naik paket.';

            throw ValidationException::withMessages([
                $errorKey => "Paket {$tenant->planLabel()} dibatasi {$limit} ".self::LABELS[$resource].'.'.$suffix,
            ]);
        }
    }

    /**
     * Jumlah yang terpakai terhadap batas paket. Outlet nonaktif tidak dihitung.
     */
    public static function count(string $resource): int
    {
        return match ($resource) {
            'users' => User::query()->count(),
            'products' => Product::query()->count(),
            'outlets' => Outlet::query()->active()->count(),
        };
    }
}
