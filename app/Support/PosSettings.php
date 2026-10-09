<?php

namespace App\Support;

use App\Enums\OrderType;
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
        'pos.block_expired_sale' => '1',
        'pos.expiry_warning_days' => '30',
        'pos.prescription_mode' => 'strict',
        'pos.allow_controlled_drugs' => '0',
        'pos.service_charge_rate' => '0',
        'pharmacy.photo_retention_years' => '0',
        'pos.near_expiry_discount_percent' => '0',
        'pos.near_expiry_discount_days' => '2',
        'pos.service_charge_dine_in_only' => '1',
    ];

    public const PRESCRIPTION_MODES = [
        'strict' => 'Ketat: obat wajib resep hanya bisa dijual dengan resep yang sudah diverifikasi',
        'warn' => 'Peringatan: cukup isi nama dokter dan pasien',
    ];

    /**
     * Nilai untuk outlet aktif: penimpaan outlet, lalu setting toko, lalu bawaan.
     */
    public static function get(string $key): string
    {
        return (string) (OutletSettings::get($key) ?? Setting::get($key, self::DEFAULTS[$key] ?? null));
    }

    /**
     * Nilai milik toko tanpa penimpaan outlet; dasar tampilan "ikuti pengaturan toko" di halaman outlet.
     */
    public static function tenantValue(string $key): string
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
        return self::parseQuickCash(self::get('pos.quick_cash'));
    }

    /**
     * @return list<int>
     */
    public static function parseQuickCash(string $json): array
    {
        $values = json_decode($json, true);

        return array_values(array_filter(array_map('intval', is_array($values) ? $values : []), fn (int $value) => $value > 0));
    }

    public static function blockExpiredSale(): bool
    {
        return self::get('pos.block_expired_sale') === '1';
    }

    public static function expiryWarningDays(): int
    {
        return max(1, min(365, (int) self::get('pos.expiry_warning_days')));
    }

    public static function prescriptionMode(): string
    {
        return self::get('pos.prescription_mode') === 'warn' ? 'warn' : 'strict';
    }

    /**
     * Narkotika & psikotropika dijual lewat kasir hanya kalau pemilik membukanya sendiri.
     */
    public static function allowControlledDrugs(): bool
    {
        return self::get('pos.allow_controlled_drugs') === '1';
    }

    /**
     * Persen service charge untuk pesanan ini; 0 kalau tidak dipungut. Bila hanya untuk makan di tempat,
     * pesanan tanpa tipe atau bawa pulang/antar tidak dikenai.
     */
    public static function serviceChargeRate(?string $orderType = null): float
    {
        $rate = max(0, min(100, (float) self::get('pos.service_charge_rate')));

        if ($rate <= 0 || (self::serviceChargeDineInOnly() && $orderType !== OrderType::DineIn->value)) {
            return 0.0;
        }

        return $rate;
    }

    public static function serviceChargeConfiguredRate(): float
    {
        return max(0, min(100, (float) self::get('pos.service_charge_rate')));
    }

    /**
     * Tanpa kapabilitas tipe pesanan semua pesanan dianggap sama, jadi batasan "hanya makan di tempat" diabaikan.
     */
    public static function serviceChargeDineInOnly(): bool
    {
        return self::get('pos.service_charge_dine_in_only') === '1' && Features::enabledAt('business.order-type');
    }

    /**
     * Potongan otomatis (persen) untuk unit dari batch yang kedaluwarsa dalam nearExpiryDiscountDays(); 0 = mati.
     */
    public static function nearExpiryDiscountPercent(): float
    {
        return Features::enabledAt('business.batch-expiry') ? max(0, min(90, (float) self::get('pos.near_expiry_discount_percent'))) : 0.0;
    }

    public static function nearExpiryDiscountDays(): int
    {
        return max(0, min(60, (int) self::get('pos.near_expiry_discount_days')));
    }

    /**
     * Masa simpan foto resep dalam tahun; 0 berarti foto tidak pernah dihapus otomatis.
     */
    public static function prescriptionPhotoRetentionYears(): int
    {
        return max(0, min(30, (int) self::tenantValue('pharmacy.photo_retention_years')));
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
