<?php

namespace App\Support;

/**
 * Mengenali kolom & nilai angka di data ekspor. Pemanggil ekspor mengirim angka yang sudah
 * diformat Indonesia ("1.250.000", "-12,50", "Rp450.000") supaya CSV & PDF terbaca apa adanya;
 * kelas ini mengembalikannya jadi angka asli untuk Excel (bisa dijumlah) dan menandai kolomnya
 * supaya rata kanan.
 */
class ExportNumber
{
    /**
     * Kata di judul kolom yang menandai nominal/kuantitas. Kolom identitas (kode, nomor, tanggal)
     * tetap teks walaupun isinya angka, mis. kode akun "1101".
     */
    private const NUMERIC_HEADER = '/\b(rp|debit|kredit|saldo|jumlah|nilai|total|sisa|dibayar|dikredit|dpp|ppn|tarif|harga|stok|kuantitas|volume|diretur|terkirim|target|kapasitas|selisih|fisik|termin)\b|\(%\)/i';

    private const IDENTITY_HEADER = '/\b(kode|no\.?|nomor|tanggal|status|nama)\b/i';

    /**
     * @param  array<int, string>  $headers
     * @return array<int, bool> per indeks kolom
     */
    public static function numericColumns(array $headers): array
    {
        return array_map(
            fn (string $header) => preg_match(self::NUMERIC_HEADER, $header) === 1 && preg_match(self::IDENTITY_HEADER, $header) !== 1,
            array_values($headers),
        );
    }

    /**
     * @return array{value: float, decimals: int, currency: bool}|null null kalau bukan angka (mis. "-", "Tersembunyi").
     */
    public static function parse(mixed $value): ?array
    {
        if (is_int($value) || is_float($value)) {
            return ['value' => (float) $value, 'decimals' => is_float($value) && floor($value) != $value ? 2 : 0, 'currency' => false];
        }

        if (! is_string($value) || ! preg_match('/^(-)?(Rp\s?)?(-)?(\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d+))?$/', trim($value), $match)) {
            return null;
        }

        $decimals = $match[5] ?? '';
        $number = (float) (str_replace('.', '', $match[4]).($decimals !== '' ? '.'.$decimals : ''));
        $negative = ($match[1] ?? '') === '-' || ($match[3] ?? '') === '-';

        return [
            'value' => $negative ? -$number : $number,
            'decimals' => strlen($decimals),
            'currency' => ($match[2] ?? '') !== '',
        ];
    }

    /**
     * Format tampilan Excel; pemisah ribuan/desimal mengikuti regional setting komputer pembuka.
     *
     * @param  array{value: float, decimals: int, currency: bool}  $number
     */
    public static function excelFormat(array $number): string
    {
        $format = '#,##0'.($number['decimals'] > 0 ? '.'.str_repeat('0', $number['decimals']) : '');

        return $number['currency'] ? '"Rp"'.$format : $format;
    }
}
