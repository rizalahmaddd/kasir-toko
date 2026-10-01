<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * Satu-satunya daftar menu aplikasi: sidebar, flyout sidebar ciut, navigasi bawah mobile, dan
 * pencarian menu di global search membaca dari sini. Modul baru cukup menambah entri di items().
 *
 * Setiap tautan boleh punya:
 * - feature: kunci Features yang harus menyala
 * - can: ability Gate, atau [ability, model] untuk policy
 * - superadmin: true bila hanya untuk Superadmin
 * - mobile: true bila layak jadi tujuan di navigasi bawah mobile (maks. 4 yang lolos izin)
 */
class Navigation
{
    /**
     * @return list<array{label: ?string, items: list<array<string, mixed>>}>
     */
    public static function items(): array
    {
        return [
            [
                'label' => null,
                'items' => [
                    ['label' => 'Dashboard', 'icon' => 'layout-dashboard', 'route' => 'dashboard', 'active' => 'dashboard', 'mobile' => true, 'mobile_label' => 'Beranda', 'keywords' => 'beranda ringkasan home'],
                    ['label' => 'Kasir', 'icon' => 'shopping-cart', 'route' => 'pos.cashier', 'active' => 'pos.*', 'feature' => 'pos.cashier', 'can' => 'pos.sell', 'mobile' => true, 'keywords' => 'pos jual transaksi penjualan bayar checkout'],
                ],
            ],
            [
                'label' => 'Penjualan',
                'items' => [
                    [
                        'key' => 'sales',
                        'label' => 'Penjualan',
                        'icon' => 'receipt',
                        'feature' => 'pos',
                        'children' => [
                            ['label' => 'Riwayat Transaksi', 'icon' => 'receipt', 'route' => 'sales.index', 'active' => 'sales.*', 'feature' => 'pos.sales', 'can' => 'pos.sell', 'mobile' => true, 'mobile_label' => 'Transaksi', 'keywords' => 'nota struk invoice batal void cetak ulang'],
                            ['label' => 'Shift Kasir', 'icon' => 'wallet', 'route' => 'shifts.index', 'active' => 'shifts.*', 'feature' => 'pos.shifts', 'can' => 'pos.sell', 'keywords' => 'buka tutup kasir laci setoran kas masuk keluar'],
                            ['label' => 'Piutang (Kasbon)', 'icon' => 'hand-coins', 'route' => 'receivables.index', 'active' => 'receivables.*', 'feature' => 'pos.receivables', 'can' => 'receivables.manage', 'keywords' => 'hutang kasbon tagihan pelunasan bon'],
                        ],
                    ],
                ],
            ],
            [
                'label' => 'Data Referensi',
                'items' => [
                    [
                        'key' => 'master-data',
                        'label' => 'Produk & Stok',
                        'icon' => 'package',
                        'children' => [
                            ['label' => 'Produk', 'icon' => 'package', 'route' => 'master-data.products', 'active' => 'master-data.products*', 'feature' => 'master-data.products', 'can' => 'view-master-data', 'mobile' => true, 'keywords' => 'barang item harga barcode sku'],
                            ['label' => 'Kategori', 'icon' => 'tags', 'route' => 'master-data.categories', 'active' => 'master-data.categories*', 'feature' => 'master-data.categories', 'can' => 'view-master-data', 'keywords' => 'kelompok jenis produk'],
                            ['label' => 'Stok Barang', 'icon' => 'warehouse', 'route' => 'inventory.stock', 'active' => 'inventory.*', 'feature' => 'inventory.stock', 'can' => 'view-master-data', 'keywords' => 'stok masuk keluar opname gudang persediaan inventory'],
                            ['label' => 'Pelanggan', 'icon' => 'users', 'route' => 'master-data.customers', 'active' => 'master-data.customers*', 'feature' => 'master-data.customers', 'can' => 'view-master-data', 'keywords' => 'customer klien kontak member'],
                        ],
                    ],
                ],
            ],
            [
                'label' => 'Sistem',
                'items' => [
                    [
                        'key' => 'reports',
                        'label' => 'Laporan',
                        'icon' => 'bar-chart-3',
                        'feature' => 'reports',
                        'children' => [
                            ['label' => 'Laporan Penjualan', 'icon' => 'trending-up', 'route' => 'reports.sales', 'feature' => 'reports.sales', 'can' => 'reports.sales.view', 'keywords' => 'omzet laba profit terlaris rekap harian'],
                            ['label' => 'Log Aktivitas', 'icon' => 'scroll-text', 'route' => 'reports.activity-log', 'feature' => 'reports.activity-log', 'can' => ['viewAny', Activity::class], 'keywords' => 'audit trail jejak riwayat login'],
                        ],
                    ],
                    [
                        'key' => 'settings',
                        'label' => 'Pengaturan',
                        'icon' => 'settings',
                        'children' => [
                            ['label' => 'Profil Perusahaan', 'icon' => 'building-2', 'route' => 'settings.company-profile', 'feature' => 'settings.company-profile', 'can' => ['update', Setting::class], 'keywords' => 'profil perusahaan toko setting kop surat logo branding'],
                            ['label' => 'Pengaturan Kasir', 'icon' => 'sliders-horizontal', 'route' => 'settings.pos', 'feature' => 'settings.pos', 'can' => 'settings.pos.manage', 'keywords' => 'pajak ppn struk printer metode pembayaran qris kasbon stok minus'],
                            ['label' => 'Layar Pelanggan', 'icon' => 'monitor-smartphone', 'route' => 'settings.customer-display', 'feature' => 'settings.customer-display', 'can' => 'settings.pos.manage', 'keywords' => 'customer display layar kedua monitor pembeli promo slideshow'],
                            ['label' => 'Peran & Perizinan', 'icon' => 'shield-alert', 'route' => 'settings.roles-and-permissions', 'active' => 'settings.roles-and-permissions*', 'feature' => 'settings.roles-and-permissions', 'can' => ['viewAny', Role::class], 'keywords' => 'role permission peran perizinan hak akses user pengguna kasir'],
                            ['label' => 'Pengaturan Fitur', 'icon' => 'toggle-right', 'route' => 'settings.features', 'superadmin' => true, 'keywords' => 'fitur modul aktif nonaktif toggle'],
                            ['label' => 'Backup & Restore', 'icon' => 'database-backup', 'route' => 'settings.backups', 'active' => 'settings.backups*', 'superadmin' => true, 'keywords' => 'backup cadangan restore pulihkan database'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Menu yang boleh dilihat user: tautan yang gagal izin/fiturnya mati dibuang, grup tanpa
     * anak dan bagian kosong ikut hilang.
     *
     * @return list<array{label: ?string, items: list<array<string, mixed>>}>
     */
    public static function forUser(User $user): array
    {
        $sections = [];

        foreach (self::items() as $section) {
            $items = [];

            foreach ($section['items'] as $item) {
                if (isset($item['children'])) {
                    if (isset($item['feature']) && ! Features::enabled($item['feature'])) {
                        continue;
                    }

                    $item['children'] = array_values(array_filter($item['children'], fn (array $link) => self::allows($user, $link)));

                    if ($item['children'] !== []) {
                        $items[] = $item;
                    }
                } elseif (self::allows($user, $item)) {
                    $items[] = $item;
                }
            }

            if ($items !== []) {
                $sections[] = ['label' => $section['label'], 'items' => $items];
            }
        }

        return $sections;
    }

    /**
     * Semua tautan (tanpa grup) yang boleh dibuka user, beserta nama bagian induknya.
     *
     * @return list<array<string, mixed>>
     */
    public static function linksForUser(User $user): array
    {
        $links = [];

        foreach (self::forUser($user) as $section) {
            foreach ($section['items'] as $item) {
                foreach ($item['children'] ?? [$item] as $link) {
                    $links[] = $link + ['section' => isset($item['children']) ? $item['label'] : ($section['label'] ?? 'Beranda')];
                }
            }
        }

        return $links;
    }

    /**
     * @param  array<string, mixed>  $link
     */
    public static function isActive(array $link): bool
    {
        return request()->routeIs($link['active'] ?? $link['route']);
    }

    /**
     * @param  array<string, mixed>  $group
     */
    public static function isGroupActive(array $group): bool
    {
        foreach ($group['children'] ?? [] as $link) {
            if (self::isActive($link)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $link
     */
    private static function allows(User $user, array $link): bool
    {
        if (($link['superadmin'] ?? false) && ! $user->isSuperAdmin()) {
            return false;
        }

        if (isset($link['feature']) && ! Features::enabled($link['feature'])) {
            return false;
        }

        if (isset($link['can'])) {
            $ability = (array) $link['can'];

            if (! $user->can($ability[0], $ability[1] ?? [])) {
                return false;
            }
        }

        return true;
    }
}
