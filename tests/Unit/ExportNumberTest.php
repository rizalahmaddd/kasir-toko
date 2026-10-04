<?php

use App\Support\ExportNumber;

test('parse reads Indonesian formatted amounts back into numbers', function (string $cell, float $value, int $decimals, bool $currency) {
    expect(ExportNumber::parse($cell))->toBe(['value' => $value, 'decimals' => $decimals, 'currency' => $currency]);
})->with([
    'thousands' => ['1.250.000', 1250000.0, 0, false],
    'decimals' => ['1.234,50', 1234.5, 2, false],
    'negative' => ['-12.500', -12500.0, 0, false],
    'small' => ['500', 500.0, 0, false],
    'rupiah' => ['Rp450.000', 450000.0, 0, true],
]);

test('parse leaves placeholders and text alone', function (string $cell) {
    expect(ExportNumber::parse($cell))->toBeNull();
})->with(['-', '', 'Tersembunyi', 'INV-2026-001', '24/09/2026', '1.5']);

test('only amount and quantity columns count as numeric, not codes or document numbers', function () {
    expect(ExportNumber::numericColumns(['Kode', 'Nama Akun', 'Saldo (Rp)', 'No. Faktur', 'Tanggal', 'Tarif (%)', 'Stok Saat Ini', 'Status', 'Debit']))
        ->toBe([false, false, true, false, false, true, true, false, true]);
});
