<?php

namespace App\Support;

class NumberFormatter
{
    /**
     * Format a monetary amount into standard Indonesian Rupiah format.
     * e.g. 450000 -> "Rp450.000"
     */
    public static function currency(float|int|string|null $amount, bool $showCents = false): string
    {
        if ($amount === null || $amount === '') {
            return 'Rp0';
        }

        $val = (float) $amount;
        if ($showCents && round($val, 2) != round($val, 0)) {
            return 'Rp'.number_format($val, 2, ',', '.');
        }

        return 'Rp'.number_format($val, 0, ',', '.');
    }

    /**
     * Format a quantity, omitting unnecessary trailing decimal zeros.
     * e.g. 5.00 -> "5", 5.50 -> "5,5", 1250.75 -> "1.250,75"
     */
    public static function quantity(float|int|string|null $quantity, int $maxDecimals = 2): string
    {
        if ($quantity === null || $quantity === '') {
            return '0';
        }

        $val = (float) $quantity;
        if ($val == (int) $val) {
            return number_format($val, 0, ',', '.');
        }

        $formatted = number_format($val, $maxDecimals, ',', '.');

        // Trim trailing zeros after comma if any
        return rtrim(rtrim($formatted, '0'), ',');
    }

    /**
     * Format any numbers embedded in narrative sentences or activity log messages.
     * Handles:
     * - "Pembayaran Rp450000.00 dicatat" -> "Pembayaran Rp450.000 dicatat"
     * - "Penagihan Rp225000.00 dicatat" -> "Penagihan Rp225.000 dicatat"
     * - "kelebihan 5.00" -> "kelebihan 5"
     * - "tersisa 0.00" -> "tersisa 0"
     * - "(+685.00)" -> "(+685)"
     */
    public static function narrative(?string $text): string
    {
        if (! $text) {
            return '';
        }

        // Already-formatted numbers (225.000 or 1.250,5) come first so they are kept intact instead of re-read as decimals.
        $number = '\d{1,3}(?:\.\d{3})+(?:,\d+)?|\d+(?:\.\d+)?';

        $text = preg_replace_callback("/(Rp\\s*)({$number})/i", function ($m) {
            return 'Rp'.self::formatRawNumber($m[2], 0);
        }, $text);

        $text = preg_replace_callback("/(\\([+-]?)({$number})\\)/", function ($m) {
            return $m[1].self::formatRawNumber($m[2]).')';
        }, $text);

        $text = preg_replace_callback("/((?:kelebihan|tersisa)\\s+)({$number})/i", function ($m) {
            return $m[1].self::formatRawNumber($m[2]);
        }, $text);

        return $text;
    }

    /**
     * Format a raw machine number (e.g. "225000.00"); leave an already-formatted one (e.g. "225.000") untouched.
     */
    private static function formatRawNumber(string $number, int $decimals = 2): string
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $number)) {
            return $number;
        }

        $val = (float) $number;

        return number_format($val, $val == (int) $val ? 0 : $decimals, ',', '.');
    }
}
