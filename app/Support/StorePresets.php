<?php

namespace App\Support;

use App\Enums\StoreType;

/**
 * Starting data per store type, applied by App\Services\StorePresetApplier: categories, optional
 * sample products, POS settings, and which optional features stay on. Prices are integer rupiah.
 *
 * A product row is [name, cost_price, price, unit, min_stock]; a null min_stock means the item is
 * made to order or digital, so its stock is not tracked.
 */
class StorePresets
{
    /**
     * Features a preset decides on. Every other toggle keeps whatever the owner chose.
     */
    public const MANAGED_FEATURES = ['pos.receivables', 'pos.customer-display'];

    private const BASE_SETTINGS = [
        'pos.tax_enabled' => '0',
        'pos.tax_rate' => '11',
        'pos.tax_label' => 'PPN',
        'pos.allow_credit' => '0',
        'pos.allow_negative_stock' => '0',
        'pos.payment_methods' => '["cash","qris","transfer","card"]',
        'pos.quick_cash' => '[10000,20000,50000,100000]',
        'pos.receipt_footer' => 'Terima kasih atas kunjungan Anda',
    ];

    /**
     * @var array<string, array{categories: array<string, list<array{0: string, 1: int, 2: int, 3: string, 4: int|null}>>, settings: array<string, string>, disabled_features: list<string>}>
     */
    private const PRESETS = [
        'warung' => [
            'categories' => [
                'Sembako' => [
                    ['Beras Medium 1kg', 12500, 14000, 'kg', 10],
                    ['Gula Pasir 1kg', 16500, 18500, 'kg', 5],
                    ['Minyak Goreng Pouch 1L', 17000, 19500, 'bks', 6],
                    ['Telur Ayam', 28000, 31000, 'kg', 3],
                    ['Tepung Terigu 1kg', 11500, 13500, 'bks', 3],
                    ['Garam Dapur 250g', 2500, 3500, 'bks', 5],
                ],
                'Mi & Makanan Instan' => [
                    ['Mi Instan Goreng', 2900, 3500, 'bks', 20],
                    ['Mi Instan Kuah Ayam Bawang', 2700, 3300, 'bks', 20],
                    ['Sarden Kaleng 155g', 9500, 12000, 'kaleng', 3],
                ],
                'Minuman' => [
                    ['Air Mineral 600ml', 2600, 4000, 'btl', 12],
                    ['Teh Kotak 200ml', 3000, 4000, 'pcs', 12],
                    ['Kopi Susu Sachet', 1300, 2000, 'sachet', 20],
                    ['Susu Kental Manis Sachet', 1400, 2000, 'sachet', 10],
                ],
                'Makanan Ringan' => [
                    ['Wafer Cokelat', 1500, 2000, 'pcs', 12],
                    ['Kacang Kulit 70g', 5000, 7000, 'bks', 5],
                    ['Keripik Singkong 100g', 4000, 6000, 'bks', 5],
                ],
                'Rokok' => [
                    ['Rokok Kretek Filter isi 12', 24500, 27000, 'bks', 5],
                    ['Rokok Putih isi 16', 31000, 34000, 'bks', 3],
                    ['Rokok Kretek Eceran', 2100, 2500, 'batang', null],
                ],
                'Kebutuhan Rumah' => [
                    ['Sabun Cuci Piring 400ml', 7000, 9000, 'bks', 3],
                    ['Deterjen Bubuk Sachet', 1100, 1500, 'sachet', 12],
                    ['Sabun Mandi Batang', 3500, 5000, 'pcs', 6],
                    ['Sampo Sachet', 900, 1500, 'sachet', 12],
                    ['Obat Nyamuk Bakar', 5000, 7000, 'box', 3],
                ],
                'Gas & Air' => [
                    ['Gas LPG 3kg (isi ulang)', 19000, 22000, 'tabung', 2],
                    ['Isi Ulang Air Galon', 4000, 7000, 'galon', null],
                ],
            ],
            'settings' => [
                'pos.allow_credit' => '1',
                'pos.allow_negative_stock' => '1',
                'pos.payment_methods' => '["cash","qris"]',
                'pos.quick_cash' => '[5000,10000,20000,50000]',
                'pos.receipt_footer' => 'Terima kasih sudah belanja. Kasbon dicatat dan bisa dicek kapan saja.',
            ],
            'disabled_features' => ['pos.customer-display'],
        ],
        'minimarket' => [
            'categories' => [
                'Minuman' => [
                    ['Air Mineral 1500ml', 4500, 6500, 'btl', 12],
                    ['Teh Botol 350ml', 3800, 5500, 'btl', 12],
                    ['Kopi Susu Kaleng 240ml', 6500, 9000, 'kaleng', 6],
                    ['Minuman Isotonik 500ml', 5200, 7500, 'btl', 6],
                    ['Susu UHT Full Cream 1L', 17500, 21500, 'pcs', 4],
                ],
                'Makanan Ringan' => [
                    ['Keripik Kentang 68g', 8500, 11500, 'bks', 6],
                    ['Biskuit Cokelat 120g', 7200, 10000, 'bks', 6],
                    ['Cokelat Batang 62g', 9500, 12500, 'pcs', 6],
                    ['Permen Mint Roll', 4000, 6000, 'pcs', 6],
                ],
                'Sembako' => [
                    ['Beras Premium 5kg', 69000, 78000, 'karung', 3],
                    ['Minyak Goreng Pouch 2L', 34000, 39500, 'bks', 4],
                    ['Gula Pasir 1kg', 16500, 18900, 'bks', 6],
                    ['Mi Instan Goreng', 2900, 3600, 'bks', 24],
                ],
                'Makanan Beku' => [
                    ['Nugget Ayam 500g', 36000, 44500, 'bks', 3],
                    ['Sosis Sapi 500g', 32000, 39000, 'bks', 3],
                    ['Es Krim Cup', 4500, 7000, 'cup', 6],
                ],
                'Perawatan Diri' => [
                    ['Pasta Gigi 190g', 11500, 14900, 'pcs', 4],
                    ['Sampo 170ml', 19000, 24500, 'btl', 4],
                    ['Sabun Cair Refill 450ml', 18500, 23900, 'bks', 4],
                    ['Pembalut Wanita isi 10', 9500, 12500, 'bks', 4],
                ],
                'Kebersihan Rumah' => [
                    ['Deterjen Cair Refill 750ml', 16500, 21000, 'bks', 4],
                    ['Pembersih Lantai 780ml', 12500, 16500, 'bks', 4],
                    ['Tisu Gulung isi 4', 14000, 18500, 'pak', 4],
                ],
                'Kebutuhan Bayi' => [
                    ['Popok Bayi M isi 20', 52000, 62000, 'pak', 3],
                    ['Minyak Telon 60ml', 14500, 18500, 'btl', 3],
                ],
            ],
            'settings' => [
                'pos.receipt_footer' => 'Terima kasih. Simpan struk ini sebagai bukti pembayaran yang sah.',
            ],
            'disabled_features' => ['pos.receivables'],
        ],
        'kafe' => [
            'categories' => [
                'Kopi' => [
                    ['Espresso', 6000, 18000, 'cup', null],
                    ['Americano', 7000, 22000, 'cup', null],
                    ['Cafe Latte', 9500, 28000, 'cup', null],
                    ['Cappuccino', 9500, 28000, 'cup', null],
                    ['Kopi Susu Gula Aren', 8500, 24000, 'cup', null],
                    ['Caramel Macchiato', 11000, 32000, 'cup', null],
                    ['Manual Brew V60', 10000, 30000, 'cup', null],
                ],
                'Non-Kopi' => [
                    ['Cokelat', 9000, 26000, 'cup', null],
                    ['Matcha Latte', 11000, 30000, 'cup', null],
                    ['Red Velvet Latte', 10000, 28000, 'cup', null],
                ],
                'Teh' => [
                    ['Teh Tarik', 6000, 20000, 'cup', null],
                    ['Lemon Tea', 5000, 18000, 'cup', null],
                    ['Lychee Tea', 7000, 22000, 'cup', null],
                ],
                'Camilan' => [
                    ['Kentang Goreng', 8000, 22000, 'porsi', null],
                    ['Pisang Goreng Keju', 7000, 20000, 'porsi', null],
                    ['Roti Bakar Cokelat', 8000, 22000, 'porsi', null],
                    ['Croissant Butter', 12000, 25000, 'pcs', null],
                ],
                'Makanan' => [
                    ['Nasi Goreng Kampung', 12000, 32000, 'porsi', null],
                    ['Mie Goreng Spesial', 11000, 30000, 'porsi', null],
                    ['Chicken Katsu Rice', 16000, 38000, 'porsi', null],
                ],
                'Tambahan' => [
                    ['Extra Shot Espresso', 3000, 6000, 'shot', null],
                    ['Air Mineral 600ml', 2600, 8000, 'btl', 12],
                ],
            ],
            'settings' => [
                'pos.tax_enabled' => '1',
                'pos.tax_rate' => '10',
                'pos.tax_label' => 'PB1',
                'pos.quick_cash' => '[20000,50000,100000,200000]',
                'pos.receipt_footer' => 'Terima kasih sudah mampir. Sampai jumpa di kunjungan berikutnya.',
            ],
            'disabled_features' => ['pos.receivables'],
        ],
        'restoran' => [
            'categories' => [
                'Paket Nasi' => [
                    ['Nasi Ayam Goreng', 13000, 25000, 'porsi', null],
                    ['Nasi Ayam Bakar', 14000, 27000, 'porsi', null],
                    ['Nasi Bebek Goreng', 20000, 38000, 'porsi', null],
                    ['Nasi Rawon', 15000, 30000, 'porsi', null],
                    ['Gurame Bakar', 35000, 75000, 'porsi', null],
                ],
                'Lauk Pauk' => [
                    ['Ayam Goreng', 9000, 18000, 'porsi', null],
                    ['Sate Ayam 10 Tusuk', 14000, 30000, 'porsi', null],
                    ['Telur Dadar', 3000, 7000, 'porsi', null],
                    ['Tempe Goreng', 1000, 3000, 'pcs', null],
                    ['Tahu Goreng', 1000, 3000, 'pcs', null],
                ],
                'Sayur & Sup' => [
                    ['Sayur Asem', 4000, 10000, 'porsi', null],
                    ['Cah Kangkung', 5000, 15000, 'porsi', null],
                    ['Sayur Lodeh', 4000, 10000, 'porsi', null],
                    ['Sop Buntut', 35000, 65000, 'porsi', null],
                ],
                'Nasi & Pelengkap' => [
                    ['Nasi Putih', 2500, 6000, 'porsi', null],
                    ['Sambal Terasi', 1000, 4000, 'porsi', null],
                    ['Kerupuk', 1000, 3000, 'pcs', null],
                ],
                'Minuman' => [
                    ['Es Teh Manis', 1500, 6000, 'gelas', null],
                    ['Es Jeruk', 3000, 10000, 'gelas', null],
                    ['Jus Alpukat', 8000, 18000, 'gelas', null],
                    ['Kopi Hitam', 2000, 6000, 'gelas', null],
                    ['Air Mineral 600ml', 2600, 6000, 'btl', 12],
                ],
                'Pencuci Mulut' => [
                    ['Es Campur', 6000, 15000, 'porsi', null],
                    ['Pisang Bakar Cokelat Keju', 6000, 17000, 'porsi', null],
                ],
            ],
            'settings' => [
                'pos.tax_enabled' => '1',
                'pos.tax_rate' => '10',
                'pos.tax_label' => 'PB1',
                'pos.quick_cash' => '[50000,100000,150000,200000]',
                'pos.receipt_footer' => 'Terima kasih, selamat menikmati. Kritik dan saran silakan sampaikan ke kasir.',
            ],
            'disabled_features' => ['pos.receivables'],
        ],
        'fashion' => [
            'categories' => [
                'Atasan Pria' => [
                    ['Kaos Polos Cotton Combed 30s', 32000, 65000, 'pcs', 6],
                    ['Kemeja Flanel Pria', 65000, 135000, 'pcs', 3],
                    ['Kemeja Batik Lengan Pendek', 75000, 150000, 'pcs', 3],
                    ['Polo Shirt Pria', 55000, 110000, 'pcs', 3],
                ],
                'Atasan Wanita' => [
                    ['Blouse Wanita Rayon', 48000, 99000, 'pcs', 3],
                    ['Tunik Wanita', 60000, 125000, 'pcs', 3],
                    ['Cardigan Rajut', 55000, 115000, 'pcs', 3],
                ],
                'Bawahan' => [
                    ['Celana Jeans Pria', 95000, 189000, 'pcs', 3],
                    ['Celana Chino Pria', 75000, 149000, 'pcs', 3],
                    ['Celana Kulot Wanita', 50000, 99000, 'pcs', 3],
                    ['Rok Plisket', 45000, 95000, 'pcs', 3],
                ],
                'Busana Muslim' => [
                    ['Gamis Wanita', 110000, 225000, 'pcs', 2],
                    ['Baju Koko Pria', 75000, 150000, 'pcs', 3],
                    ['Hijab Segi Empat Voal', 25000, 55000, 'pcs', 6],
                    ['Hijab Bergo Instan', 20000, 45000, 'pcs', 6],
                ],
                'Pakaian Anak' => [
                    ['Kaos Anak', 20000, 45000, 'pcs', 6],
                    ['Setelan Anak', 45000, 95000, 'pcs', 3],
                    ['Dress Anak', 50000, 105000, 'pcs', 3],
                ],
                'Aksesoris' => [
                    ['Kaos Kaki', 5000, 12000, 'pasang', 12],
                    ['Ikat Pinggang Kulit', 35000, 75000, 'pcs', 3],
                    ['Topi Baseball', 25000, 55000, 'pcs', 3],
                    ['Dompet Pria', 40000, 85000, 'pcs', 3],
                ],
            ],
            'settings' => [
                'pos.quick_cash' => '[50000,100000,200000,300000]',
                'pos.receipt_footer' => 'Penukaran barang maksimal 3 hari dengan struk dan label yang masih utuh.',
            ],
            'disabled_features' => ['pos.receivables'],
        ],
        'bangunan' => [
            'categories' => [
                'Semen & Bata' => [
                    ['Semen Portland 40kg', 58000, 65000, 'sak', 10],
                    ['Semen Portland 50kg', 68000, 76000, 'sak', 10],
                    ['Mortar Perekat Bata Ringan 40kg', 75000, 88000, 'sak', 5],
                    ['Bata Merah', 700, 1000, 'pcs', 500],
                ],
                'Besi & Baja' => [
                    ['Besi Beton 8mm', 48000, 56000, 'batang', 20],
                    ['Besi Beton 10mm', 75000, 86000, 'batang', 20],
                    ['Hollow Galvanis 4x4', 35000, 43000, 'batang', 10],
                    ['Kawat Bendrat', 24000, 30000, 'kg', 5],
                ],
                'Cat & Pelapis' => [
                    ['Cat Tembok Interior 5kg', 85000, 110000, 'galon', 3],
                    ['Cat Kayu & Besi 1kg', 55000, 70000, 'kaleng', 3],
                    ['Thinner 1L', 18000, 24000, 'kaleng', 3],
                    ['Kuas Cat 3 Inci', 8000, 12000, 'pcs', 6],
                ],
                'Pipa & Sanitasi' => [
                    ['Pipa PVC 1/2 Inci 4m', 22000, 28000, 'batang', 10],
                    ['Pipa PVC 3 Inci 4m', 85000, 105000, 'batang', 5],
                    ['Keran Air Plastik', 8000, 13000, 'pcs', 6],
                    ['Lem Pipa PVC', 7000, 10000, 'pcs', 6],
                ],
                'Listrik' => [
                    ['Kabel NYA 1,5mm', 4000, 5500, 'meter', 50],
                    ['Lampu LED 12 Watt', 15000, 22000, 'pcs', 6],
                    ['Stop Kontak Arde', 12000, 18000, 'pcs', 6],
                    ['Saklar Tunggal', 9000, 14000, 'pcs', 6],
                ],
                'Paku & Perkakas' => [
                    ['Paku 5cm', 16000, 20000, 'kg', 5],
                    ['Meteran 5m', 18000, 27000, 'pcs', 3],
                    ['Palu Kambing', 35000, 50000, 'pcs', 2],
                    ['Gergaji Kayu', 40000, 58000, 'pcs', 2],
                ],
                'Kayu & Triplek' => [
                    ['Triplek 9mm', 110000, 135000, 'lembar', 5],
                    ['Kayu Kaso 5/7 4m', 38000, 48000, 'batang', 10],
                ],
            ],
            'settings' => [
                'pos.allow_credit' => '1',
                'pos.payment_methods' => '["cash","qris","transfer"]',
                'pos.quick_cash' => '[50000,100000,500000,1000000]',
                'pos.receipt_footer' => 'Periksa barang sebelum meninggalkan toko. Barang yang sudah dibeli tidak dapat dikembalikan.',
            ],
            'disabled_features' => ['pos.customer-display'],
        ],
        'konter' => [
            'categories' => [
                'Pulsa' => [
                    ['Pulsa 5.000', 5200, 7000, 'pcs', null],
                    ['Pulsa 10.000', 10200, 12000, 'pcs', null],
                    ['Pulsa 20.000', 19900, 22000, 'pcs', null],
                    ['Pulsa 25.000', 24900, 27000, 'pcs', null],
                    ['Pulsa 50.000', 49500, 52000, 'pcs', null],
                    ['Pulsa 100.000', 98500, 102000, 'pcs', null],
                ],
                'Paket Data' => [
                    ['Paket Data 10GB 30 Hari', 48000, 55000, 'pcs', null],
                    ['Paket Data 25GB 30 Hari', 85000, 95000, 'pcs', null],
                ],
                'Token Listrik' => [
                    ['Token Listrik 20.000', 21500, 23000, 'pcs', null],
                    ['Token Listrik 50.000', 51500, 53000, 'pcs', null],
                    ['Token Listrik 100.000', 101500, 103000, 'pcs', null],
                ],
                'Kartu Perdana & Voucher' => [
                    ['Kartu Perdana Kosong', 5000, 10000, 'pcs', 5],
                    ['Voucher Data Fisik 5GB', 20000, 25000, 'pcs', 5],
                ],
                'Aksesoris HP' => [
                    ['Charger Fast Charging 18W', 35000, 65000, 'pcs', 3],
                    ['Kabel Data Type-C', 12000, 25000, 'pcs', 5],
                    ['Earphone', 15000, 35000, 'pcs', 3],
                    ['Tempered Glass', 5000, 20000, 'pcs', 10],
                    ['Softcase HP', 8000, 25000, 'pcs', 10],
                    ['Power Bank 10.000mAh', 110000, 165000, 'pcs', 2],
                    ['Kartu Memori 32GB', 45000, 75000, 'pcs', 3],
                ],
                'Jasa' => [
                    ['Jasa Pasang Tempered Glass', 0, 5000, 'pcs', null],
                    ['Jasa Instal Ulang HP', 0, 50000, 'pcs', null],
                ],
            ],
            'settings' => [
                'pos.allow_credit' => '1',
                'pos.quick_cash' => '[10000,20000,50000,100000]',
                'pos.receipt_footer' => 'Pulsa dan token yang sudah masuk tidak dapat dibatalkan. Simpan struk untuk klaim garansi aksesoris.',
            ],
            'disabled_features' => ['pos.customer-display'],
        ],
        'apotek' => [
            'categories' => [
                'Demam & Nyeri' => [
                    ['Paracetamol 500mg Tablet', 3000, 5000, 'strip', 10],
                    ['Paracetamol Sirup Anak 60ml', 8500, 13000, 'btl', 3],
                    ['Ibuprofen 200mg Tablet', 6000, 9500, 'strip', 5],
                    ['Plester Kompres Demam', 8000, 12000, 'box', 3],
                ],
                'Batuk & Flu' => [
                    ['Obat Batuk Sirup 100ml', 14000, 19500, 'btl', 3],
                    ['Obat Flu Tablet', 2500, 4000, 'strip', 10],
                    ['Tablet Hisap Pelega Tenggorokan', 8000, 12000, 'box', 4],
                    ['Minyak Kayu Putih 60ml', 19000, 24500, 'btl', 4],
                ],
                'Pencernaan' => [
                    ['Antasida Tablet Kunyah', 5000, 8000, 'strip', 5],
                    ['Oralit Sachet', 1000, 2000, 'sachet', 10],
                    ['Obat Diare Tablet', 5000, 8000, 'strip', 5],
                ],
                'Vitamin & Suplemen' => [
                    ['Vitamin C 1000mg Effervescent', 17000, 23500, 'tube', 3],
                    ['Multivitamin isi 30 Tablet', 45000, 58000, 'box', 2],
                    ['Vitamin Anak Sirup 60ml', 22000, 29500, 'btl', 2],
                ],
                'P3K & Perawatan Luka' => [
                    ['Plester Luka isi 10', 3500, 5500, 'pak', 5],
                    ['Kasa Steril', 5000, 8000, 'pak', 5],
                    ['Povidone Iodine 30ml', 12000, 16500, 'btl', 3],
                    ['Alkohol 70% 100ml', 6000, 9500, 'btl', 3],
                    ['Perban Elastis', 12000, 18000, 'pcs', 2],
                ],
                'Alat Kesehatan' => [
                    ['Masker Medis 3 Ply isi 50', 22000, 32000, 'box', 3],
                    ['Termometer Digital', 25000, 38000, 'pcs', 2],
                    ['Alat Tes Kehamilan', 5000, 12000, 'pcs', 3],
                ],
            ],
            'settings' => [
                'pos.quick_cash' => '[10000,20000,50000,100000]',
                'pos.receipt_footer' => 'Semoga lekas sembuh. Baca aturan pakai sebelum minum obat.',
            ],
            'disabled_features' => ['pos.receivables'],
        ],
        'bakery' => [
            'categories' => [
                'Roti Manis' => [
                    ['Roti Cokelat Keju', 3000, 7000, 'pcs', null],
                    ['Roti Sosis', 4500, 10000, 'pcs', null],
                    ['Roti Abon', 4500, 10000, 'pcs', null],
                    ['Donat Gula', 2000, 5000, 'pcs', null],
                    ['Croissant Butter', 7000, 16000, 'pcs', null],
                ],
                'Roti Tawar' => [
                    ['Roti Tawar Kupas', 9000, 18000, 'bks', null],
                    ['Roti Tawar Gandum', 11000, 22000, 'bks', null],
                ],
                'Kue Potong' => [
                    ['Brownies Panggang Potong', 4500, 10000, 'pcs', null],
                    ['Bolu Pandan Potong', 3500, 8000, 'pcs', null],
                    ['Cheese Cake Slice', 12000, 28000, 'pcs', null],
                    ['Lapis Legit Potong', 8000, 18000, 'pcs', null],
                ],
                'Kue Ulang Tahun' => [
                    ['Kue Ulang Tahun 16cm', 90000, 175000, 'pcs', null],
                    ['Kue Ulang Tahun 20cm', 130000, 250000, 'pcs', null],
                    ['Kue Ulang Tahun 24cm', 180000, 350000, 'pcs', null],
                ],
                'Kue Kering' => [
                    ['Nastar 500g', 65000, 110000, 'toples', 3],
                    ['Kastengel 500g', 70000, 120000, 'toples', 3],
                    ['Putri Salju 500g', 55000, 95000, 'toples', 3],
                ],
                'Minuman' => [
                    ['Kopi Susu Dingin', 6000, 15000, 'cup', null],
                    ['Teh Manis Dingin', 2000, 6000, 'cup', null],
                ],
                'Perlengkapan Kue' => [
                    ['Lilin Angka', 2000, 5000, 'pcs', 10],
                    ['Kotak Kue 20x20', 3000, 5000, 'pcs', 10],
                ],
            ],
            'settings' => [
                'pos.quick_cash' => '[20000,50000,100000,200000]',
                'pos.receipt_footer' => 'Terima kasih. Roti dan kue paling enak dinikmati di hari yang sama.',
            ],
            'disabled_features' => ['pos.receivables'],
        ],
        'lainnya' => [
            'categories' => [
                'Umum' => [],
                'Jasa' => [],
            ],
            'settings' => [
                'pos.allow_credit' => '1',
            ],
            'disabled_features' => [],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function categories(StoreType $type): array
    {
        return array_keys(self::PRESETS[$type->value]['categories']);
    }

    /**
     * @return array<string, list<array{0: string, 1: int, 2: int, 3: string, 4: int|null}>>
     */
    public static function catalog(StoreType $type): array
    {
        return self::PRESETS[$type->value]['categories'];
    }

    public static function sampleProductCount(StoreType $type): int
    {
        return array_sum(array_map('count', self::catalog($type)));
    }

    /**
     * Every key a preset writes, so applying a second preset fully replaces the first.
     *
     * @return array<string, string>
     */
    public static function settings(StoreType $type): array
    {
        return array_replace(self::BASE_SETTINGS, self::PRESETS[$type->value]['settings']);
    }

    /**
     * @return list<string>
     */
    public static function disabledFeatures(StoreType $type): array
    {
        return self::PRESETS[$type->value]['disabled_features'];
    }

    /**
     * The settings that matter when choosing, decoded for display and the API.
     *
     * @return array{tax_enabled: bool, tax_rate: float, tax_label: string, allow_credit: bool, allow_negative_stock: bool, payment_methods: list<string>, quick_cash: list<int>, receipt_footer: string}
     */
    public static function settingsSummary(StoreType $type): array
    {
        $settings = self::settings($type);

        return [
            'tax_enabled' => $settings['pos.tax_enabled'] === '1',
            'tax_rate' => (float) $settings['pos.tax_rate'],
            'tax_label' => $settings['pos.tax_label'],
            'allow_credit' => $settings['pos.allow_credit'] === '1',
            'allow_negative_stock' => $settings['pos.allow_negative_stock'] === '1',
            'payment_methods' => json_decode($settings['pos.payment_methods'], true),
            'quick_cash' => json_decode($settings['pos.quick_cash'], true),
            'receipt_footer' => $settings['pos.receipt_footer'],
        ];
    }
}
