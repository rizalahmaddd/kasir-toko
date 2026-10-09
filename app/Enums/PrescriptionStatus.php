<?php

namespace App\Enums;

enum PrescriptionStatus: string
{
    case Pending = 'pending';
    case PartiallyDispensed = 'partially_dispensed';
    case Dispensed = 'dispensed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Belum Ditebus',
            self::PartiallyDispensed => 'Ditebus Sebagian',
            self::Dispensed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::PartiallyDispensed => 'sky',
            self::Dispensed => 'emerald',
            self::Cancelled => 'slate',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::PartiallyDispensed], true);
    }
}
