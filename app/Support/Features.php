<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Sakelar fitur per modul yang diatur Superadmin di Pengaturan Fitur. Fitur yang dimatikan
 * tertutup untuk semua akun (termasuk Superadmin), apa pun peran dan izinnya; data dan izin
 * tetap tersimpan sehingga menyalakannya lagi langsung memulihkan akses.
 */
class Features
{
    public const DISABLED_KEY = 'features.disabled';

    public const ENABLED_KEY = 'features.enabled';

    /**
     * Modul yang fiturnya mati sampai dinyalakan (disimpan sebagai daftar enabled), supaya fitur
     * baru di modul ini tidak otomatis menyala di toko yang sudah berjalan.
     */
    public const OPT_IN_MODULES = ['business'];

    public const MODULE_CATEGORIES = [
        'data' => 'Data & Operasional',
        'business' => 'Fitur Khusus Usaha',
        'system' => 'Laporan & Sistem',
    ];

    /**
     * Pola nama route per fitur. Route yang tidak tercakup di sini (dashboard, profil, halaman
     * Pengaturan Fitur & Backup) selalu terbuka. Modul baru cukup didaftarkan di sini supaya
     * muncul di halaman Pengaturan Fitur.
     *
     * @var array<string, array{label: string, icon: string, category: string, description: string, features: array<string, array{label: string, description: string, routes: list<string>}>}>
     */
    public const MODULES = [
        'pos' => [
            'label' => 'Kasir & Penjualan',
            'category' => 'data',
            'icon' => 'shopping-cart',
            'description' => 'Layar kasir, riwayat transaksi, shift kasir, dan kasbon pelanggan.',
            'features' => [
                'cashier' => [
                    'label' => 'Kasir',
                    'description' => 'Layar transaksi penjualan, transaksi tertunda, dan cetak struk.',
                    'routes' => ['pos.*'],
                ],
                'sales' => [
                    'label' => 'Riwayat Transaksi',
                    'description' => 'Daftar transaksi, detail, cetak ulang struk, dan pembatalan.',
                    'routes' => ['sales.*'],
                ],
                'shifts' => [
                    'label' => 'Shift Kasir',
                    'description' => 'Rekap buka/tutup kasir, kas masuk/keluar, dan selisih uang laci.',
                    'routes' => ['shifts.*'],
                ],
                'customer-display' => [
                    'label' => 'Layar Pelanggan',
                    'description' => 'Layar kedua yang menghadap pembeli: keranjang, total, kembalian, dan QRIS bernominal.',
                    'routes' => ['display.*', 'pos.display.*'],
                ],
                'receivables' => [
                    'label' => 'Piutang (Kasbon)',
                    'description' => 'Transaksi yang belum lunas beserta pencatatan pelunasannya.',
                    'routes' => ['receivables.*'],
                ],
            ],
        ],
        'master-data' => [
            'label' => 'Master Data',
            'category' => 'data',
            'icon' => 'database',
            'description' => 'Data referensi yang dipakai modul lain: produk, kategori, dan pelanggan.',
            'features' => [
                'products' => [
                    'label' => 'Produk',
                    'description' => 'Daftar produk beserta harga jual, HPP, barcode, dan batas stok minimum.',
                    'routes' => ['master-data.products*'],
                ],
                'categories' => [
                    'label' => 'Kategori',
                    'description' => 'Pengelompokan produk untuk filter di layar kasir dan laporan.',
                    'routes' => ['master-data.categories*'],
                ],
                'customers' => [
                    'label' => 'Pelanggan',
                    'description' => 'Daftar pelanggan beserta kontak, alamat, dan kasbonnya.',
                    'routes' => ['master-data.customers*'],
                ],
            ],
        ],
        'inventory' => [
            'label' => 'Stok',
            'category' => 'data',
            'icon' => 'warehouse',
            'description' => 'Stok masuk, stok keluar, stok opname, dan kartu stok per produk.',
            'features' => [
                'stock' => [
                    'label' => 'Stok Barang',
                    'description' => 'Posisi stok, penyesuaian stok, dan riwayat mutasi.',
                    'routes' => ['inventory.*'],
                ],
                'opname' => [
                    'label' => 'Stok Opname',
                    'description' => 'Dokumen hitung stok fisik per outlet: dihitung bersama (web & HP), diperiksa, lalu stok disesuaikan sebesar selisihnya.',
                    'routes' => ['inventory.opname*'],
                ],
                'transfer' => [
                    'label' => 'Transfer Stok Antar Outlet',
                    'description' => 'Memindahkan stok dari satu outlet ke outlet lain.',
                    'routes' => ['inventory.transfers*'],
                ],
            ],
        ],
        'business' => [
            'label' => 'Fitur Khusus Usaha',
            'category' => 'business',
            'icon' => 'briefcase-business',
            'description' => 'Kemampuan khas jenis usaha tertentu. Dinyalakan otomatis oleh preset jenis toko dan bisa diubah kapan saja.',
            'features' => [
                'product-attributes' => [
                    'label' => 'Atribut Produk Khusus',
                    'description' => 'Isian tambahan di data produk sesuai jenis toko, mis. zat aktif, golongan obat, atau merek.',
                    'routes' => [],
                ],
                'multi-unit' => [
                    'label' => 'Multi-Satuan',
                    'description' => 'Satuan bertingkat dengan konversi dan harga sendiri, mis. box/strip/tablet atau dus/pak/pcs.',
                    'routes' => [],
                ],
                'batch-expiry' => [
                    'label' => 'Batch & Kedaluwarsa',
                    'description' => 'Stok per nomor batch dan tanggal kedaluwarsa, penjualan FEFO, dan peringatan barang hampir kedaluwarsa.',
                    'routes' => ['inventory.expiry*'],
                ],
                'prescription' => [
                    'label' => 'Resep Obat',
                    'description' => 'Golongan obat, obat keras wajib resep, pencatatan & verifikasi resep, salinan resep, dan etiket.',
                    'routes' => ['pharmacy.*'],
                ],
                'components' => [
                    'label' => 'Komposisi & Racikan',
                    'description' => 'Produk yang dibuat dari bahan lain dan memotong stok bahannya saat terjual, mis. puyer racikan atau paket.',
                    'routes' => [],
                ],
                'modifiers' => [
                    'label' => 'Pilihan Tambahan (Modifier)',
                    'description' => 'Pilihan di kasir dengan harga tambahan, mis. ukuran, level gula, extra shot, atau topping.',
                    'routes' => ['master-data.modifiers*'],
                ],
                'order-type' => [
                    'label' => 'Tipe Pesanan & Meja',
                    'description' => 'Makan di tempat, bawa pulang, atau antar; nomor meja & antrean, open bill per meja, dan tiket dapur.',
                    'routes' => ['kitchen.*', 'print.kitchen-ticket'],
                ],
                'tiered-price' => [
                    'label' => 'Harga Grosir',
                    'description' => 'Harga satuan turun otomatis saat jumlah beli mencapai batas tertentu.',
                    'routes' => [],
                ],
                'variants' => [
                    'label' => 'Varian Produk',
                    'description' => 'Produk induk dengan kombinasi ukuran × warna (atau pilihan lain) sebagai SKU anak yang punya stok, harga, dan barcode sendiri.',
                    'routes' => [],
                ],
                'serial-number' => [
                    'label' => 'Nomor Seri / IMEI',
                    'description' => 'Setiap unit dicatat nomor seri/IMEI-nya saat stok masuk dan dipilih saat dijual, lengkap dengan masa garansi di struk.',
                    'routes' => ['inventory.serials*'],
                ],
                'pre-order' => [
                    'label' => 'Pesanan & Servis',
                    'description' => 'Pesanan dengan tanggal ambil dan uang muka (DP), atau tiket servis, yang dilunasi lewat kasir saat diambil.',
                    'routes' => ['orders.*'],
                ],
                'delivery-note' => [
                    'label' => 'Surat Jalan',
                    'description' => 'Cetak surat jalan berisi barang dan alamat kirim untuk transaksi yang diantar, beserta status terkirim.',
                    'routes' => ['delivery-notes.*'],
                ],
            ],
        ],
        'reports' => [
            'label' => 'Laporan',
            'category' => 'system',
            'icon' => 'bar-chart-3',
            'description' => 'Laporan penjualan dan jejak audit aktivitas pengguna.',
            'features' => [
                'sales' => [
                    'label' => 'Laporan Penjualan',
                    'description' => 'Omzet, laba kotor, produk terlaris, dan rekap metode pembayaran.',
                    'routes' => ['reports.sales'],
                ],
                'stock-variance' => [
                    'label' => 'Laporan Selisih Stok',
                    'description' => 'Selisih stok hasil opname per periode, outlet, kategori, dan alasan, beserta nilainya.',
                    'routes' => ['reports.stock-variance'],
                ],
                'activity-log' => [
                    'label' => 'Log Aktivitas',
                    'description' => 'Audit trail aktivitas pengguna, riwayat login, dan perubahan data.',
                    'routes' => ['reports.activity-log'],
                ],
            ],
        ],
        'settings' => [
            'label' => 'Pengaturan',
            'category' => 'system',
            'icon' => 'settings',
            'description' => 'Identitas toko, aturan kasir, dan hak akses pengguna berbasis peran (RBAC).',
            'features' => [
                'company-profile' => [
                    'label' => 'Profil Perusahaan',
                    'description' => 'Nama aplikasi, identitas perusahaan, logo, dan kop surat dokumen cetak.',
                    'routes' => ['settings.company-profile'],
                ],
                'pos' => [
                    'label' => 'Pengaturan Kasir',
                    'description' => 'Pajak, metode pembayaran, kasbon, stok minus, dan isi struk.',
                    'routes' => ['settings.pos'],
                ],
                'outlets' => [
                    'label' => 'Outlet',
                    'description' => 'Daftar outlet, akses pengguna, serta pajak, harga, dan struk per outlet.',
                    'routes' => ['settings.outlets*'],
                ],
                'customer-display' => [
                    'label' => 'Pengaturan Layar Pelanggan',
                    'description' => 'Tema, teks sambutan, promo, dan slideshow di layar pelanggan.',
                    'routes' => ['settings.customer-display'],
                ],
                'data-export' => [
                    'label' => 'Ekspor Data Toko',
                    'description' => 'Unduh seluruh data toko (produk, transaksi, pelanggan, stok) sebagai berkas CSV.',
                    'routes' => ['settings.data-export*'],
                ],
                'roles-and-permissions' => [
                    'label' => 'Peran & Perizinan',
                    'description' => 'Manajemen peran (roles), izin akses (permissions), dan penugasan peran ke pengguna.',
                    'routes' => ['settings.roles-and-permissions*'],
                ],
            ],
        ],
    ];

    /** @var array<string, list<string>> */
    private static array $urlCache = [];

    /**
     * Kunci modul ("master-data") atau fitur ("master-data.customers"). Modul dianggap aktif selama
     * modulnya tidak dimatikan dan masih ada minimal satu fitur di dalamnya yang menyala.
     */
    public static function enabled(string $key): bool
    {
        if (! self::isKnown($key)) {
            throw new InvalidArgumentException("Fitur [{$key}] tidak terdaftar di Features::MODULES.");
        }

        $disabled = self::disabledKeys();

        if (str_contains($key, '.')) {
            return ! in_array(Str::before($key, '.'), $disabled, true) && self::featureSwitchedOn($key, $disabled);
        }

        if (in_array($key, $disabled, true)) {
            return false;
        }

        foreach (array_keys(self::MODULES[$key]['features']) as $feature) {
            if (self::featureSwitchedOn("{$key}.{$feature}", $disabled)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Versi per outlet dari enabled(), untuk kasir, transaksi, dan menu operasional. Master data,
     * laporan, dan data integritas stok tetap memakai enabled(): kalau form produk ikut outlet,
     * field yang tersembunyi di outlet lain akan terlewat saat disimpan dan datanya hilang.
     */
    public static function enabledAt(string $key, ?int $outletId = null): bool
    {
        return self::enabled($key)
            && OutletFeatures::allows($key, $outletId ?? app(CurrentOutlet::class)->idOrPrimary());
    }

    /**
     * Sakelar fitur itu sendiri, tanpa melihat sakelar modulnya.
     *
     * @param  list<string>|null  $disabled
     */
    public static function featureSwitchedOn(string $key, ?array $disabled = null): bool
    {
        if (self::isOptIn($key)) {
            return in_array($key, self::enabledKeys(), true);
        }

        return ! in_array($key, $disabled ?? self::disabledKeys(), true);
    }

    public static function isOptIn(string $key): bool
    {
        return in_array(Str::before($key, '.'), self::OPT_IN_MODULES, true);
    }

    /**
     * Satu route bisa dimiliki beberapa fitur, dan semuanya harus menyala.
     *
     * @return list<string>
     */
    public static function featuresForRoute(?string $routeName): array
    {
        if ($routeName === null) {
            return [];
        }

        $features = [];

        foreach (self::MODULES as $module => $definition) {
            foreach ($definition['features'] as $feature => $featureDefinition) {
                if (Str::is($featureDefinition['routes'], $routeName)) {
                    $features[] = "{$module}.{$feature}";
                }
            }
        }

        return $features;
    }

    public static function allowsRoute(?string $routeName): bool
    {
        return self::allEnabled(self::featuresForRoute($routeName));
    }

    /**
     * Untuk tautan yang hanya tersedia sebagai URL (komponen dashboard, notifikasi, x-feature-link).
     * URL di luar aplikasi atau yang tidak cocok dengan route mana pun dianggap boleh.
     */
    public static function allowsUrl(?string $url): bool
    {
        if ($url === null || $url === '') {
            return true;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (! array_key_exists($path, self::$urlCache)) {
            try {
                $routeName = Route::getRoutes()->match(Request::create($path))->getName();
            } catch (HttpException) {
                $routeName = null;
            }

            self::$urlCache[$path] = self::featuresForRoute($routeName);
        }

        return self::allEnabled(self::$urlCache[$path]);
    }

    /**
     * @param  list<string>  $features
     */
    private static function allEnabled(array $features): bool
    {
        foreach ($features as $feature) {
            if (! self::enabled($feature)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public static function disabledKeys(): array
    {
        // Sidebar memanggil ini puluhan kali per halaman dan cache Setting ada di database,
        // jadi hasilnya ditahan per request (scoped: ikut dibuang di queue worker).
        if (! app()->bound(self::DISABLED_KEY)) {
            app()->scoped(self::DISABLED_KEY, function (): array {
                $decoded = json_decode(Setting::get(self::DISABLED_KEY) ?? '[]', true);

                return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
            });
        }

        return app(self::DISABLED_KEY);
    }

    /**
     * @param  list<string>  $keys
     */
    public static function setDisabled(array $keys): void
    {
        $valid = array_values(array_filter(array_unique($keys), fn (string $key) => self::isKnown($key)));
        sort($valid);

        Setting::put(self::DISABLED_KEY, json_encode($valid));
        app()->forgetInstance(self::DISABLED_KEY);
    }

    /**
     * Fitur modul opt-in yang sedang dinyalakan.
     *
     * @return list<string>
     */
    public static function enabledKeys(): array
    {
        if (! app()->bound(self::ENABLED_KEY)) {
            app()->scoped(self::ENABLED_KEY, function (): array {
                $decoded = json_decode(Setting::get(self::ENABLED_KEY) ?? '[]', true);

                return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
            });
        }

        return app(self::ENABLED_KEY);
    }

    /**
     * @param  list<string>  $keys
     */
    public static function setEnabled(array $keys): void
    {
        $valid = array_values(array_filter(array_unique($keys), fn (string $key) => str_contains($key, '.') && self::isKnown($key) && self::isOptIn($key)));
        sort($valid);

        Setting::put(self::ENABLED_KEY, json_encode($valid));
        app()->forgetInstance(self::ENABLED_KEY);
    }

    /**
     * Daftar fitur modul opt-in (kapabilitas usaha), mis. "business.multi-unit".
     *
     * @return list<string>
     */
    public static function optInFeatures(): array
    {
        $keys = [];

        foreach (self::OPT_IN_MODULES as $module) {
            foreach (array_keys(self::MODULES[$module]['features']) as $feature) {
                $keys[] = "{$module}.{$feature}";
            }
        }

        return $keys;
    }

    public static function isKnown(string $key): bool
    {
        if (! str_contains($key, '.')) {
            return isset(self::MODULES[$key]);
        }

        [$module, $feature] = explode('.', $key, 2);

        return isset(self::MODULES[$module]['features'][$feature]);
    }
}
