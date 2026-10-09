<?php

namespace App\Enums;

enum StockCountStatus: string
{
    case Counting = 'counting';
    case Review = 'review';
    case Posting = 'posting';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Counting => 'Sedang dihitung',
            self::Review => 'Diperiksa',
            self::Posting => 'Sedang diproses',
            self::Posted => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Counting => 'sky',
            self::Review => 'amber',
            self::Posting => 'slate',
            self::Posted => 'emerald',
            self::Cancelled => 'rose',
        };
    }

    /**
     * Dokumen yang masih berjalan: menahan lingkup barangnya dari opname lain di outlet yang sama.
     */
    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Counting, self::Review, self::Posting];
    }
}
