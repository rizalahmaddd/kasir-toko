<?php

namespace App\Services\Pos;

/**
 * Rumus total transaksi. resources/js/cart-math.js (kasir web) dan cart_math.dart (mobile) menghitung hal
 * yang sama; kalau rumus di sini berubah, ubah juga di sana, karena checkout menolak total yang tidak cocok.
 * tests/fixtures/cart-parity.json dipakai ketiganya untuk memastikan hasilnya identik.
 */
class CartCalculator
{
    /**
     * Baris: harga satuan + pilihan tambahan per satuan, dikali jumlah, dikurangi diskon baris. Service charge
     * dihitung dari subtotal setelah diskon transaksi, lalu pajak dari subtotal setelah diskon + service charge.
     *
     * @param  list<array{price: int, quantity: float, discount: int, modifiers?: int}>  $lines
     * @return array{lines: list<array{gross: int, discount: int, total: int}>, subtotal: int, discount_amount: int, service_charge_amount: int, tax_amount: int, total: int}
     */
    public static function calculate(array $lines, ?string $discountType, float $discountValue, float $taxRate, float $serviceRate = 0): array
    {
        $computed = [];
        $subtotal = 0;

        foreach ($lines as $line) {
            $gross = (int) round(($line['price'] + ($line['modifiers'] ?? 0)) * $line['quantity']);
            $discount = max(0, min($line['discount'], $gross));
            $computed[] = ['gross' => $gross, 'discount' => $discount, 'total' => $gross - $discount];
            $subtotal += $gross - $discount;
        }

        $discountAmount = match ($discountType) {
            'percent' => (int) round($subtotal * max(0, min(100, $discountValue)) / 100),
            'amount' => (int) max(0, min(round($discountValue), $subtotal)),
            default => 0,
        };

        $base = $subtotal - $discountAmount;
        $serviceAmount = (int) round($base * max(0, min(100, $serviceRate)) / 100);
        $taxAmount = (int) round(($base + $serviceAmount) * $taxRate / 100);

        return [
            'lines' => $computed,
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'service_charge_amount' => $serviceAmount,
            'tax_amount' => $taxAmount,
            'total' => $base + $serviceAmount + $taxAmount,
        ];
    }

    /**
     * Potongan ED dekat untuk satu baris: unit yang masih tersedia di batch hampir kedaluwarsa ($available, sudah
     * dikurangi baris sebelumnya untuk produk yang sama) dipotong $percent dari harga satuannya.
     */
    public static function nearExpiryDiscount(int $price, float $quantity, float $available, float $percent): int
    {
        if ($percent <= 0 || $available <= 0) {
            return 0;
        }

        return (int) round($price * min($quantity, $available) * $percent / 100);
    }

    /**
     * Harga grosir satuan dasar: tingkat dengan min_quantity terbesar yang sudah tercapai, dan tidak pernah
     * lebih mahal dari harga biasa. $quantity = jumlah satuan dasar produk itu di seluruh baris tanpa satuan lain.
     *
     * @param  list<array{min: float, price: int}>  $tiers
     */
    public static function tierPrice(int $basePrice, array $tiers, float $quantity): int
    {
        $price = $basePrice;
        $reached = -1.0;

        foreach ($tiers as $tier) {
            if ($quantity + 0.0001 >= $tier['min'] && $tier['min'] > $reached) {
                $reached = $tier['min'];
                $price = min($basePrice, $tier['price']);
            }
        }

        return $price;
    }
}
