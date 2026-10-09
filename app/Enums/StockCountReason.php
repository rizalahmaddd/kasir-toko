<?php

namespace App\Enums;

enum StockCountReason: string
{
    case Damaged = 'damaged';
    case Expired = 'expired';
    case Lost = 'lost';
    case InputError = 'input_error';
    case UnrecordedReceipt = 'unrecorded_receipt';
    case UnrecordedSale = 'unrecorded_sale';
    case Sample = 'sample';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Damaged => 'Rusak',
            self::Expired => 'Kedaluwarsa',
            self::Lost => 'Hilang/dicuri',
            self::InputError => 'Salah catat sebelumnya',
            self::UnrecordedReceipt => 'Barang masuk tidak tercatat',
            self::UnrecordedSale => 'Penjualan tidak tercatat',
            self::Sample => 'Dipakai/sampel',
            self::Other => 'Lainnya',
        };
    }
}
