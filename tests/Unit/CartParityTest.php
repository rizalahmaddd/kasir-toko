<?php

use App\Services\Pos\CartCalculator;
use App\Services\Pos\KitchenTicketService;

$fixture = json_decode(file_get_contents(__DIR__.'/../fixtures/cart-parity.json'), true);

test('totals match the shared parity fixture', function (array $case) {
    $result = CartCalculator::calculate($case['lines'], $case['discount_type'], (float) $case['discount_value'], (float) $case['tax_rate'], (float) $case['service_rate']);

    expect(array_intersect_key($result, $case['expected']))->toBe($case['expected']);
})->with(array_map(fn (array $case) => [$case], array_column($fixture['totals'], null, 'name')));

test('tier prices match the shared parity fixture', function (array $case) {
    expect(CartCalculator::tierPrice($case['base'], $case['tiers'], (float) $case['quantity']))->toBe($case['expected']);
})->with(array_map(fn (array $case) => [$case], $fixture['tiers']));

test('kitchen line signatures match the shared parity fixture', function (array $case) {
    expect(KitchenTicketService::signature($case['line']))->toBe($case['expected']);
})->with(array_map(fn (array $case) => [$case], $fixture['signatures']));

test('near-expiry discounts match the shared parity fixture', function (array $case) {
    expect(CartCalculator::nearExpiryDiscount($case['price'], (float) $case['quantity'], (float) $case['available'], (float) $case['percent']))->toBe($case['expected']);
})->with(array_map(fn (array $case) => [$case], $fixture['near_expiry']));
