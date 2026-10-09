<?php

namespace App\Enums;

enum BatchSource: string
{
    case StockIn = 'stock_in';
    case Opening = 'opening';
    case Adjustment = 'adjustment';
    case Transfer = 'transfer';
    case SaleVoid = 'sale_void';

    public function label(): string
    {
        return match ($this) {
            self::StockIn => 'Stok Masuk',
            self::Opening => 'Saldo Awal',
            self::Adjustment => 'Penyesuaian',
            self::Transfer => 'Transfer',
            self::SaleVoid => 'Batal Penjualan',
        };
    }
}
