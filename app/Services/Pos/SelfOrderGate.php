<?php

namespace App\Services\Pos;

use App\Models\CashShift;
use App\Support\Features;
use App\Support\OutletSettings;
use App\Support\PosSettings;

/**
 * Apakah outlet sedang menerima pesanan QR. Aplikasi tidak punya jam buka, jadi gerbangnya hanya dua:
 * sakelar manual per outlet dan adanya shift kasir yang berjalan (tanpa shift tidak ada yang melayani).
 */
class SelfOrderGate
{
    public const REASONS = [
        'feature_off' => 'Pemesanan lewat QR belum diaktifkan di outlet ini.',
        'disabled' => 'Pemesanan lewat QR sedang ditutup.',
        'no_shift' => 'Kasir belum buka. Pemesanan dibuka setelah kasir memulai shift.',
    ];

    /**
     * Null bila pemesanan terbuka; selain itu kunci REASONS.
     */
    public static function closedReason(int $outletId): ?string
    {
        if (! Features::enabledAt('business.self-order', $outletId)) {
            return 'feature_off';
        }

        if ((OutletSettings::get('pos.self_order_enabled', $outletId) ?? PosSettings::tenantValue('pos.self_order_enabled')) !== '1') {
            return 'disabled';
        }

        if (! CashShift::query()->withoutGlobalScopes()->where('outlet_id', $outletId)->whereNull('closed_at')->exists()) {
            return 'no_shift';
        }

        return null;
    }

    public static function message(?string $reason): ?string
    {
        return $reason === null ? null : (self::REASONS[$reason] ?? self::REASONS['disabled']);
    }
}
