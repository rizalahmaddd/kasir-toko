<?php

namespace App\Enums;

enum OrderType: string
{
    case DineIn = 'dine_in';
    case TakeAway = 'take_away';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::DineIn => 'Makan di Tempat',
            self::TakeAway => 'Bawa Pulang',
            self::Delivery => 'Antar',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::DineIn => 'Dine-in',
            self::TakeAway => 'Take away',
            self::Delivery => 'Delivery',
        };
    }
}
