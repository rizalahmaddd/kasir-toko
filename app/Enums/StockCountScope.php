<?php

namespace App\Enums;

enum StockCountScope: string
{
    case All = 'all';
    case Categories = 'categories';
    case Products = 'products';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Semua barang',
            self::Categories => 'Kategori tertentu',
            self::Products => 'Pilih barang',
        };
    }
}
