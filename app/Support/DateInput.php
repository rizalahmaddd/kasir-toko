<?php

namespace App\Support;

/**
 * Tanggal dari input form atau query string URL (filter laporan) yang belum tentu valid.
 */
class DateInput
{
    /**
     * Tanggal "Y-m-d" yang benar-benar ada di kalender, atau null.
     */
    public static function valid(?string $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }
}
