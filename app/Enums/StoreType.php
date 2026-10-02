<?php

namespace App\Enums;

enum StoreType: string
{
    case Warung = 'warung';
    case Minimarket = 'minimarket';
    case Cafe = 'kafe';
    case Restaurant = 'restoran';
    case Fashion = 'fashion';
    case BuildingSupply = 'bangunan';
    case PhoneCounter = 'konter';
    case Pharmacy = 'apotek';
    case Bakery = 'bakery';
    case Other = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Warung => 'Warung / Kelontong',
            self::Minimarket => 'Minimarket',
            self::Cafe => 'Kafe / Coffee Shop',
            self::Restaurant => 'Restoran / Rumah Makan',
            self::Fashion => 'Fashion / Pakaian',
            self::BuildingSupply => 'Toko Bangunan',
            self::PhoneCounter => 'Konter HP & Pulsa',
            self::Pharmacy => 'Apotek / Toko Obat',
            self::Bakery => 'Bakery / Toko Kue',
            self::Other => 'Lainnya',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Warung => 'Sembako, jajanan, rokok, dan kebutuhan harian. Kasbon pelanggan langganan aktif.',
            self::Minimarket => 'Barang kemasan dengan stok tercatat rapi, tanpa kasbon.',
            self::Cafe => 'Menu kopi dan minuman racikan, dibuat saat dipesan. Pajak PB1 10%.',
            self::Restaurant => 'Menu makanan dan minuman per porsi, dibuat saat dipesan. Pajak PB1 10%.',
            self::Fashion => 'Pakaian, celana, dan aksesoris dengan stok per barang.',
            self::BuildingSupply => 'Semen, besi, cat, pipa, dan perkakas. Kasbon untuk tukang dan proyek.',
            self::PhoneCounter => 'Pulsa, paket data, voucher, dan aksesoris HP. Kasbon pelanggan langganan.',
            self::Pharmacy => 'Obat bebas, vitamin, dan alat kesehatan dengan stok minimum.',
            self::Bakery => 'Roti, kue potong, dan pesanan kue ulang tahun.',
            self::Other => 'Mulai dengan satu kategori umum dan pengaturan bawaan.',
        };
    }

    /**
     * Lucide icon name.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Warung => 'store',
            self::Minimarket => 'shopping-basket',
            self::Cafe => 'coffee',
            self::Restaurant => 'utensils-crossed',
            self::Fashion => 'shirt',
            self::BuildingSupply => 'hammer',
            self::PhoneCounter => 'smartphone',
            self::Pharmacy => 'pill',
            self::Bakery => 'croissant',
            self::Other => 'package',
        };
    }
}
