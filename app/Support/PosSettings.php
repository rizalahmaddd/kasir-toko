<?php

namespace App\Support;

use App\Enums\PaymentMethod;
use App\Models\Setting;

/**
 * Pengaturan kasir yang dibaca di halaman POS, checkout, dan struk. Disimpan di tabel settings
 * (key "pos.*") supaya ikut cache Setting dan tercatat di audit trail.
 */
class PosSettings
{
    public const DEFAULTS = [
        'pos.tax_enabled' => '0',
        'pos.tax_rate' => '11',
        'pos.tax_label' => 'PPN',
        'pos.allow_negative_stock' => '0',
        'pos.allow_credit' => '1',
        'pos.payment_methods' => '["cash","qris","transfer","card"]',
        'pos.receipt_width' => '58',
        'pos.receipt_header' => '',
        'pos.receipt_footer' => 'Terima kasih atas kunjungan Anda',
        'pos.auto_print' => '0',
        'pos.quick_cash' => '[10000,20000,50000,100000]',
        'pos.qris_payload' => '',
    ];

    public static function get(string $key): string
    {
        return (string) Setting::get($key, self::DEFAULTS[$key] ?? null);
    }

    public static function taxEnabled(): bool
    {
        return self::get('pos.tax_enabled') === '1';
    }

    public static function taxRate(): float
    {
        return self::taxEnabled() ? max(0, min(100, (float) self::get('pos.tax_rate'))) : 0.0;
    }

    public static function taxLabel(): string
    {
        return self::get('pos.tax_label') ?: 'Pajak';
    }

    public static function allowNegativeStock(): bool
    {
        return self::get('pos.allow_negative_stock') === '1';
    }

    public static function allowCredit(): bool
    {
        return self::get('pos.allow_credit') === '1';
    }

    /**
     * Tunai selalu tersedia: kembalian dan rekap laci kasir bergantung padanya.
     *
     * @return list<PaymentMethod>
     */
    public static function paymentMethods(): array
    {
        $enabled = json_decode(self::get('pos.payment_methods'), true);
        $enabled = is_array($enabled) ? $enabled : [];

        return array_values(array_filter(
            PaymentMethod::cases(),
            fn (PaymentMethod $method) => $method === PaymentMethod::Cash || in_array($method->value, $enabled, true),
        ));
    }

    public static function receiptWidth(): string
    {
        return self::get('pos.receipt_width') === '80' ? '80' : '58';
    }

    public static function autoPrint(): bool
    {
        return self::get('pos.auto_print') === '1';
    }

    /**
     * @return list<int>
     */
    public static function quickCash(): array
    {
        $values = json_decode(self::get('pos.quick_cash'), true);

        return array_values(array_filter(array_map('intval', is_array($values) ? $values : []), fn (int $value) => $value > 0));
    }

    /**
     * QRIS statis toko yang sudah diperiksa saat diunggah; null kalau belum diatur atau rusak.
     */
    public static function qrisPayload(): ?string
    {
        $payload = trim(self::get('pos.qris_payload'));

        return $payload !== '' && Qris::problem($payload) === null ? $payload : null;
    }
}
