<?php

use App\Services\Pos\CartCalculator;

test('line and transaction discounts are applied before tax', function () {
    $result = CartCalculator::calculate([
        ['price' => 12500, 'quantity' => 2, 'discount' => 1000],
        ['price' => 18000, 'quantity' => 0.5, 'discount' => 0],
    ], 'percent', 10, 11);

    expect($result['subtotal'])->toBe(33000)
        ->and($result['discount_amount'])->toBe(3300)
        ->and($result['tax_amount'])->toBe(3267)
        ->and($result['total'])->toBe(32967);
});

test('discounts never exceed what they discount', function () {
    $result = CartCalculator::calculate([['price' => 5000, 'quantity' => 1, 'discount' => 9000]], 'amount', 99999, 0);

    expect($result['lines'][0]['total'])->toBe(0)
        ->and($result['total'])->toBe(0);
});
