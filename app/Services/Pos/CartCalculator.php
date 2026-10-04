<?php

namespace App\Services\Pos;

/**
 * Rumus total transaksi. resources/js/pos.js menghitung hal yang sama untuk tampilan; kalau
 * rumus di sini berubah, ubah juga di sana, karena checkout menolak total yang tidak cocok.
 */
class CartCalculator
{
    /**
     * @param  list<array{price: int, quantity: float, discount: int}>  $lines
     * @return array{lines: list<array{gross: int, discount: int, total: int}>, subtotal: int, discount_amount: int, tax_amount: int, total: int}
     */
    public static function calculate(array $lines, ?string $discountType, float $discountValue, float $taxRate): array
    {
        $computed = [];
        $subtotal = 0;

        foreach ($lines as $line) {
            $gross = (int) round($line['price'] * $line['quantity']);
            $discount = max(0, min($line['discount'], $gross));
            $computed[] = ['gross' => $gross, 'discount' => $discount, 'total' => $gross - $discount];
            $subtotal += $gross - $discount;
        }

        $discountAmount = match ($discountType) {
            'percent' => (int) round($subtotal * max(0, min(100, $discountValue)) / 100),
            'amount' => (int) max(0, min(round($discountValue), $subtotal)),
            default => 0,
        };

        $taxable = $subtotal - $discountAmount;
        $taxAmount = (int) round($taxable * $taxRate / 100);

        return [
            'lines' => $computed,
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'total' => $taxable + $taxAmount,
        ];
    }
}
