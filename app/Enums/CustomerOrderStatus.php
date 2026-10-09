<?php

namespace App\Enums;

enum CustomerOrderStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Ready = 'ready';
    case PickedUp = 'picked_up';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Diterima',
            self::InProgress => 'Dikerjakan',
            self::Ready => 'Siap Diambil',
            self::PickedUp => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'sky',
            self::InProgress => 'amber',
            self::Ready => 'emerald',
            self::PickedUp => 'slate',
            self::Cancelled => 'rose',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::PickedUp, self::Cancelled], true);
    }
}
