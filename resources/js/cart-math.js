// Sama persis dengan App\Services\Pos\CartCalculator; tests/fixtures/cart-parity.json menguji keduanya.

export function calculate(lines, discountType, discountValue, taxRate, serviceRate = 0) {
    const computed = [];
    let subtotal = 0;

    for (const line of lines) {
        const gross = Math.round((Number(line.price) + Number(line.modifiers || 0)) * Number(line.quantity));
        const discount = Math.max(0, Math.min(Math.round(Number(line.discount) || 0), gross));
        computed.push({ gross, discount, total: gross - discount });
        subtotal += gross - discount;
    }

    const value = Number(discountValue) || 0;
    let discountAmount = 0;
    if (discountType === 'percent') {
        discountAmount = Math.round((subtotal * Math.max(0, Math.min(100, value))) / 100);
    } else if (discountType === 'amount') {
        discountAmount = Math.max(0, Math.min(Math.round(value), subtotal));
    }

    const base = subtotal - discountAmount;
    const serviceAmount = Math.round((base * Math.max(0, Math.min(100, Number(serviceRate) || 0))) / 100);
    const taxAmount = Math.round(((base + serviceAmount) * (Number(taxRate) || 0)) / 100);

    return { lines: computed, subtotal, discountAmount, serviceAmount, taxAmount, total: base + serviceAmount + taxAmount };
}

// Potongan ED dekat satu baris; available = sisa unit ED dekat produk itu setelah baris sebelumnya.
export function nearExpiryDiscount(price, quantity, available, percent) {
    if (!(percent > 0) || !(available > 0)) {
        return 0;
    }

    return Math.round((price * Math.min(Number(quantity), available) * percent) / 100);
}

export function tierPrice(basePrice, tiers, quantity) {
    let price = basePrice;
    let reached = -1;

    for (const tier of tiers || []) {
        if (quantity + 0.0001 >= tier.min && tier.min > reached) {
            reached = tier.min;
            price = Math.min(basePrice, tier.price);
        }
    }

    return price;
}

// Penanda baris untuk tiket dapur; sama dengan KitchenTicketService::signature.
export function lineSignature(line) {
    const modifiers = (line.modifiers || []).map((m) => Number(m.id)).sort((a, b) => a - b).join('-');

    return [Number(line.product_id) || 0, Number(line.unit_id) || 0, modifiers, String(line.note || '').trim()].join(':');
}
