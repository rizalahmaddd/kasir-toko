import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import { calculate, lineSignature, nearExpiryDiscount, tierPrice } from '../../resources/js/cart-math.js';

const fixture = JSON.parse(readFileSync(new URL('../fixtures/cart-parity.json', import.meta.url)));

for (const c of fixture.totals) {
    test(`totals: ${c.name}`, () => {
        const r = calculate(c.lines, c.discount_type, c.discount_value, c.tax_rate, c.service_rate);
        assert.deepEqual(
            { subtotal: r.subtotal, discount_amount: r.discountAmount, service_charge_amount: r.serviceAmount, tax_amount: r.taxAmount, total: r.total },
            c.expected,
        );
    });
}

fixture.tiers.forEach((c, i) => {
    test(`tier price #${i + 1}`, () => assert.equal(tierPrice(c.base, c.tiers, c.quantity), c.expected));
});

fixture.signatures.forEach((c, i) => {
    test(`signature #${i + 1}`, () => assert.equal(lineSignature(c.line), c.expected));
});

fixture.near_expiry.forEach((c, i) => {
    test(`near-expiry discount #${i + 1}`, () => assert.equal(nearExpiryDiscount(c.price, c.quantity, c.available, c.percent), c.expected));
});
