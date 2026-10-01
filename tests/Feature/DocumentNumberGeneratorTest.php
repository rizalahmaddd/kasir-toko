<?php

use App\Services\DocumentNumberGenerator;

test('numbers increment sequentially per prefix and year', function () {
    $generator = new DocumentNumberGenerator;

    expect($generator->next('PO'))->toBe('PO-'.now()->year.'-0001');
    expect($generator->next('PO'))->toBe('PO-'.now()->year.'-0002');
    expect($generator->next('PO'))->toBe('PO-'.now()->year.'-0003');
});

test('different prefixes have independent sequences', function () {
    $generator = new DocumentNumberGenerator;

    expect($generator->next('PO'))->toBe('PO-'.now()->year.'-0001');
    expect($generator->next('GRN'))->toBe('GRN-'.now()->year.'-0001');
    expect($generator->next('PO'))->toBe('PO-'.now()->year.'-0002');
});

test('a fixed year is respected independently of the current year', function () {
    $generator = new DocumentNumberGenerator;

    expect($generator->next('PO', 4, 2025))->toBe('PO-2025-0001');
    expect($generator->next('PO', 4, 2026))->toBe('PO-2026-0001');
    expect($generator->next('PO', 4, 2025))->toBe('PO-2025-0002');
});
