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
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Penjualan',
            self::SaleVoid => 'Batal Penjualan',
            self::StockIn => 'Stok Masuk',
            self::StockOut => 'Stok Keluar',
            self::Opname => 'Stok Opname',
            self::Initial => 'Stok Awal',
            self::TransferOut => 'Transfer Keluar',
            self::TransferIn => 'Transfer Masuk',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Sale, self::StockOut, self::TransferOut => 'slate',
            self::SaleVoid => 'amber',
            self::StockIn, self::Initial, self::TransferIn => 'emerald',
            self::Opname => 'sky',
        };
    }
}
