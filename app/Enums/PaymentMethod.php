<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Qris = 'qris';
    case Transfer = 'transfer';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Qris => 'QRIS',
            self::Transfer => 'Transfer',
            self::Card => 'Kartu Debit/Kredit',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Card => 'Kartu',
            default => $this->label(),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Cash => 'banknote',
            self::Qris => 'qr-code',
            self::Transfer => 'landmark',
            self::Card => 'credit-card',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $method) => [$method->value => $method->label()])->all();
    }
}
