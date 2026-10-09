<?php

namespace App\Support;

use App\Models\Outlet;
use App\Models\Setting;

/**
 * Identitas outlet di struk dan dokumen cetak. Nama toko tetap dari profil perusahaan; nama outlet
 * hanya ditambahkan di toko multi-outlet, dan alamat/telepon outlet menggantikan milik toko kalau diisi.
 */
class OutletIdentity
{
    /**
     * @return array{name: ?string, address: ?string, phone: ?string}
     */
    public static function for(?Outlet $outlet): array
    {
        $showName = $outlet !== null && app(CurrentOutlet::class)->isMultiOutlet();

        return [
            'name' => $showName ? $outlet->name : null,
            'address' => filled($outlet?->address) ? $outlet->address : (Setting::get('company_address') ?: null),
            'phone' => filled($outlet?->phone) ? $outlet->phone : (Setting::get('company_phone') ?: null),
        ];
    }
}
