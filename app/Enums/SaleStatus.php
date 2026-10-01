<?php

namespace App\Enums;

enum SaleStatus: string
{
    case Completed = 'completed';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'SELESAI',
            self::Voided => 'DIBATALKAN',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Completed => 'emerald',
            self::Voided => 'rose',
        };
    }
}
