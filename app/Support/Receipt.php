<?php

namespace App\Support;

use App\Models\Sale;

/**
 * Isi struk dalam bentuk teks untuk dikirim lewat WhatsApp, saat printer tidak ada atau pelanggan
 * minta struk digital.
 */
class Receipt
{
    public static function text(Sale $sale): string
    {
        $sale->loadMissing('items', 'payments', 'cashier');

        $lines = [
            '*'.Branding::companyName().'*',
            $sale->number.' · '.$sale->sold_at->translatedFormat('d M Y H:i'),
            '',
        ];

        foreach ($sale->items as $item) {
            $lines[] = $item->product_name;
            $lines[] = '  '.NumberFormatter::quantity($item->quantity).' x '.NumberFormatter::currency($item->price).' = '.NumberFormatter::currency($item->total + $item->discount_amount);

            if ($item->discount_amount > 0) {
                $lines[] = '  Diskon -'.NumberFormatter::currency($item->discount_amount);
            }
        }

        $lines[] = '';
        $lines[] = 'Subtotal: '.NumberFormatter::currency($sale->subtotal);

        if ($sale->discount_amount > 0) {
            $lines[] = 'Diskon: -'.NumberFormatter::currency($sale->discount_amount);
        }

        if ($sale->tax_amount > 0) {
            $lines[] = PosSettings::taxLabel().' '.NumberFormatter::quantity($sale->tax_rate).'%: '.NumberFormatter::currency($sale->tax_amount);
        }

        $lines[] = '*Total: '.NumberFormatter::currency($sale->total).'*';

        foreach ($sale->payments as $payment) {
            $amount = $payment->method->value === 'cash' && $payment->kind === 'sale' ? $sale->cash_received : $payment->amount;
            $lines[] = $payment->method->label().': '.NumberFormatter::currency($amount);
        }

        if ($sale->change_amount > 0) {
            $lines[] = 'Kembali: '.NumberFormatter::currency($sale->change_amount);
        }

        if ($sale->due_amount > 0) {
            $lines[] = 'Sisa (kasbon): '.NumberFormatter::currency($sale->due_amount);
        }

        if ($footer = PosSettings::get('pos.receipt_footer')) {
            $lines[] = '';
            $lines[] = $footer;
        }

        return implode("\n", $lines);
    }

    public static function whatsappUrl(Sale $sale): string
    {
        $phone = self::whatsappNumber($sale->customer?->phone);

        return 'https://wa.me/'.($phone ?? '').'?text='.rawurlencode(self::text($sale));
    }

    public static function whatsappNumber(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if ($digits === '' || strlen($digits) < 9) {
            return null;
        }

        return str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;
    }
}
