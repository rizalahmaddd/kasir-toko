<?php

use App\Support\NumberFormatter;

test('currency formats numbers into Indonesian Rupiah format', function () {
    expect(NumberFormatter::currency(450000))->toBe('Rp450.000');
    expect(NumberFormatter::currency('225000.00'))->toBe('Rp225.000');
    expect(NumberFormatter::currency('1500.50', showCents: true))->toBe('Rp1.500,50');
    expect(NumberFormatter::currency('1500.50'))->toBe('Rp1.501');
    expect(NumberFormatter::currency(0))->toBe('Rp0');
});

test('quantity trims trailing zeroes and formats with Indonesian comma decimal separator', function () {
    expect(NumberFormatter::quantity(5.00))->toBe('5');
    expect(NumberFormatter::quantity('0.00'))->toBe('0');
    expect(NumberFormatter::quantity(685.00))->toBe('685');
    expect(NumberFormatter::quantity(1234.50))->toBe('1.234,5');
    expect(NumberFormatter::quantity(1234.56))->toBe('1.234,56');
});

test('narrative formats unformatted numbers in log and notification text', function () {
    expect(NumberFormatter::narrative('Pembayaran Rp450000.00 dicatat untuk INV-PB-2026-0001.'))
        ->toBe('Pembayaran Rp450.000 dicatat untuk INV-PB-2026-0001.');

    expect(NumberFormatter::narrative('Penagihan Rp225000.00 dicatat untuk INV-PJ-2026-0001.'))
        ->toBe('Penagihan Rp225.000 dicatat untuk INV-PJ-2026-0001.');

    expect(NumberFormatter::narrative('Penyesuaian disetujui untuk INV-2026-0005 (kelebihan 5.00).'))
        ->toBe('Penyesuaian disetujui untuk INV-2026-0005 (kelebihan 5).');

    expect(NumberFormatter::narrative('Stok barang menipis, tersisa 0.00.'))
        ->toBe('Stok barang menipis, tersisa 0.');

    expect(NumberFormatter::narrative('Pembayaran INV-2026-0001 melebihi tagihan (+685.00) ditolak sistem.'))
        ->toBe('Pembayaran INV-2026-0001 melebihi tagihan (+685) ditolak sistem.');
});

test('narrative leaves already formatted numbers intact', function () {
    expect(NumberFormatter::narrative('Penagihan Rp225.000 dicatat untuk INV-PJ-2026-0001.'))
        ->toBe('Penagihan Rp225.000 dicatat untuk INV-PJ-2026-0001.');

    expect(NumberFormatter::narrative('Pembayaran Rp1.450.000 dicatat untuk INV-PB-2026-0001.'))
        ->toBe('Pembayaran Rp1.450.000 dicatat untuk INV-PB-2026-0001.');

    expect(NumberFormatter::narrative('Penyesuaian disetujui untuk INV-2026-0005 (kelebihan 1.250,5).'))
        ->toBe('Penyesuaian disetujui untuk INV-2026-0005 (kelebihan 1.250,5).');

    expect(NumberFormatter::narrative('Stok barang menipis, tersisa 2.500.'))
        ->toBe('Stok barang menipis, tersisa 2.500.');
});
