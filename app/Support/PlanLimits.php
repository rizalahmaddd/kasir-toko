<?php

namespace App\Support;

use App\Models\Product;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Batas jumlah data per paket langganan (config/saas.php). Dicek sebelum data baru dibuat;
 * data yang sudah ada tidak disentuh saat toko turun paket.
 */
class PlanLimits
{
    private const LABELS = ['users' => 'pengguna', 'products' => 'produk'];

    /**
     * @throws ValidationException
     */
    public static function ensureCanAdd(string $resource, string $errorKey): void
    {
        $tenant = app(CurrentTenant::class)->get();
        $limit = $tenant?->limit($resource);

        if ($limit === null) {
            return;
        }

        $count = match ($resource) {
            'users' => User::query()->count(),
            'products' => Product::query()->count(),
        };

        if ($count >= $limit) {
            throw ValidationException::withMessages([
                $errorKey => "Paket {$tenant->planLabel()} dibatasi {$limit} ".self::LABELS[$resource].'. Hubungi admin layanan untuk naik paket.',
            ]);
        }
    }
}
