<?php

namespace Database\Seeders;

use App\Services\TenantProvisioner;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Definisi lengkap hak akses (perizinan) sistem yang dikelompokkan per modul. Halaman
     * Peran & Perizinan membaca daftar ini, jadi modul baru cukup menambah grupnya di sini.
     *
     * @var array<string, array<string, array{label: string, description: string}>>
     */
    public const PERMISSION_GROUPS = [
        'Kasir & Penjualan' => [
            'pos.sell' => [
                'label' => 'Akses Kasir',
                'description' => 'Membuka layar kasir, membuka/menutup shift sendiri, dan membuat transaksi.',
            ],
            'pos.discount' => [
                'label' => 'Beri Diskon',
                'description' => 'Memberi diskon per barang maupun diskon total di layar kasir.',
            ],
            'pos.void' => [
                'label' => 'Batalkan Transaksi',
                'description' => 'Membatalkan transaksi yang sudah selesai; stok dan uang dikembalikan.',
            ],
            'sales.view' => [
                'label' => 'Lihat Semua Transaksi',
                'description' => 'Melihat riwayat transaksi semua kasir. Tanpa izin ini kasir hanya melihat transaksinya sendiri.',
            ],
            'shifts.manage' => [
                'label' => 'Kelola Shift Semua Kasir',
                'description' => 'Melihat rekap dan menutup shift milik kasir lain.',
            ],
            'receivables.manage' => [
                'label' => 'Kelola Piutang (Kasbon)',
                'description' => 'Melihat daftar kasbon pelanggan dan mencatat pelunasannya.',
            ],
        ],
        'Produk & Stok' => [
            'master-data.view' => [
                'label' => 'Lihat Master Data',
                'description' => 'Melihat daftar produk, kategori, dan pelanggan.',
            ],
            'master-data.manage' => [
                'label' => 'Kelola Master Data',
                'description' => 'Menambah, mengubah, dan menghapus produk, kategori, dan pelanggan.',
            ],
            'inventory.manage' => [
                'label' => 'Kelola Stok',
                'description' => 'Mencatat stok masuk, stok keluar, dan stok opname.',
            ],
        ],
        'Laporan & Audit' => [
            'reports.sales.view' => [
                'label' => 'Laporan Penjualan',
                'description' => 'Melihat omzet, laba kotor, produk terlaris, dan rekap metode pembayaran.',
            ],
            'reports.activity.view' => [
                'label' => 'Log Aktivitas (Audit Trail)',
                'description' => 'Melihat seluruh jejak audit aktivitas pengguna sistem.',
            ],
        ],
        'Pengaturan & Keamanan' => [
            'settings.company.manage' => [
                'label' => 'Pengaturan Profil Perusahaan',
                'description' => 'Mengatur nama aplikasi, identitas perusahaan, kop surat cetak, dan logo.',
            ],
            'settings.pos.manage' => [
                'label' => 'Pengaturan Kasir',
                'description' => 'Mengatur pajak, metode pembayaran, kasbon, stok minus, dan isi struk.',
            ],
            'roles.manage' => [
                'label' => 'Kelola Peran & Perizinan',
                'description' => 'Membuat peran baru, mengubah perizinan, dan mengatur matriks akses.',
            ],
            'users.manage' => [
                'label' => 'Kelola Penugasan Peran Pengguna',
                'description' => 'Menugaskan dan mencabut peran dari akun pengguna sistem.',
            ],
        ],
    ];

    /**
     * Izin bawaan per peran; "Kembalikan ke default" di halaman Peran & Perizinan memakai daftar ini.
     *
     * @var array<string, list<string>>
     */
    public const DEFAULT_ROLE_PERMISSIONS = [
        'superadmin' => ['*'],
        'admin' => [
            'pos.sell', 'pos.discount', 'pos.void', 'sales.view', 'shifts.manage', 'receivables.manage',
            'master-data.view', 'master-data.manage', 'inventory.manage',
            'reports.sales.view', 'reports.activity.view',
            'settings.company.manage', 'settings.pos.manage',
            'users.manage',
        ],
        'kasir' => [
            'pos.sell', 'receivables.manage',
            'master-data.view',
        ],
        'staff' => [
            'master-data.view', 'inventory.manage',
        ],
    ];

    /**
     * Izin global beserta pemetaannya ke peran tenant yang sedang aktif (lihat TenantProvisioner).
     */
    public function run(): void
    {
        app(TenantProvisioner::class)->seedRoles();
    }
}
