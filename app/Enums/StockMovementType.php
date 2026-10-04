<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Sale = 'sale';
    case SaleVoid = 'sale_void';
    case StockIn = 'stock_in';
    case StockOut = 'stock_out';
    case Opname = 'opname';
    case Initial = 'initial';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Penjualan',
            self::SaleVoid => 'Batal Penjualan',
            self::StockIn => 'Stok Masuk',
            self::StockOut => 'Stok Keluar',
            self::Opname => 'Stok Opname',
            self::Initial => 'Stok Awal',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Sale, self::StockOut => 'slate',
            self::SaleVoid => 'amber',
            self::StockIn, self::Initial => 'emerald',
            self::Opname => 'sky',
        };
    }
}
