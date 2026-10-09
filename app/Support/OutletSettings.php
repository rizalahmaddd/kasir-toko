<?php

namespace App\Support;

use App\Enums\PaymentMethod;
use App\Models\OutletSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Setting kasir yang boleh berbeda per outlet. Outlet tanpa penimpaan mengikuti nilai toko
 * (Setting); PosSettings::get() membaca lapisan ini lebih dulu. Menghapus penimpaan mengembalikan
 * outlet ke nilai toko.
 */
class OutletSettings
{
    /**
     * @var list<string>
     */
    public const KEYS = [
        'pos.tax_enabled',
        'pos.tax_rate',
        'pos.tax_label',
        'pos.service_charge_rate',
        'pos.service_charge_dine_in_only',
        'pos.payment_methods',
        'pos.receipt_width',
        'pos.receipt_header',
        'pos.receipt_footer',
        'pos.auto_print',
        'pos.qris_payload',
        'pos.allow_credit',
        'pos.allow_negative_stock',
        'pos.quick_cash',
        'pos.prescription_mode',
        'pos.allow_controlled_drugs',
        'pos.block_expired_sale',
        'pos.near_expiry_discount_percent',
        'pos.near_expiry_discount_days',
    ];

    /**
     * Kunci per bagian form; bagian dianggap mengikuti toko bila tidak satu pun kuncinya ditimpa.
     *
     * @var array<string, list<string>>
     */
    private const SECTION_KEYS = [
        'rules' => ['pos.allow_credit', 'pos.allow_negative_stock', 'pos.quick_cash'],
        'pharmacy' => ['pos.prescription_mode', 'pos.allow_controlled_drugs', 'pos.block_expired_sale', 'pos.near_expiry_discount_percent', 'pos.near_expiry_discount_days'],
    ];

    /**
     * Nilai yang ditimpa outlet, null kalau outlet mengikuti nilai toko.
     */
    public static function get(string $key, ?int $outletId = null): ?string
    {
        $outletId ??= app(CurrentOutlet::class)->idOrPrimary();

        if ($outletId === null || ! in_array($key, self::KEYS, true)) {
            return null;
        }

        return self::overrides($outletId)[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public static function overrides(int $outletId): array
    {
        return Cache::rememberForever(self::cacheKey($outletId), fn () => OutletSetting::query()
            ->where('outlet_id', $outletId)
            ->whereNotNull('value')
            ->pluck('value', 'key')
            ->all());
    }

    /**
     * Null menghapus penimpaan sehingga outlet kembali mengikuti nilai toko.
     */
    public static function put(int $outletId, string $key, ?string $value): void
    {
        if (! in_array($key, self::KEYS, true)) {
            return;
        }

        $row = OutletSetting::query()->where('outlet_id', $outletId)->where('key', $key)->first();

        if ($value === null) {
            $row?->delete();

            return;
        }

        if ($row) {
            $row->update(['value' => $value]);

            return;
        }

        OutletSetting::query()->create(['outlet_id' => $outletId, 'key' => $key, 'value' => $value]);
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public static function putMany(int $outletId, array $values): void
    {
        foreach ($values as $key => $value) {
            self::put($outletId, $key, $value);
        }
    }

    /**
     * Pengaturan outlet per bagian untuk form: tiap bagian punya `inherit` (true bila outlet mengikuti
     * nilai toko) dan nilai yang berlaku (penimpaan outlet, atau nilai toko bila mengikuti).
     *
     * @return array{tax: array{inherit: bool, enabled: bool, rate: string, label: string}, service: array{inherit: bool, rate: string, dine_in_only: bool}, payments: array{inherit: bool, methods: list<string>}, receipt: array{inherit: bool, width: string, header: string, footer: string, auto_print: bool}, qris: array{inherit: bool, payload: string}, rules: array{inherit: bool, allow_credit: bool, allow_negative_stock: bool, quick_cash: list<int>}, pharmacy: array{inherit: bool, prescription_mode: string, allow_controlled_drugs: bool, block_expired_sale: bool, near_expiry_discount_percent: string, near_expiry_discount_days: string}}
     */
    public static function sections(int $outletId): array
    {
        $own = self::overrides($outletId);
        $value = fn (string $key): string => $own[$key] ?? PosSettings::tenantValue($key);
        $methods = json_decode($value('pos.payment_methods'), true);

        return [
            'tax' => [
                'inherit' => ! isset($own['pos.tax_enabled']) && ! isset($own['pos.tax_rate']) && ! isset($own['pos.tax_label']),
                'enabled' => $value('pos.tax_enabled') === '1',
                'rate' => $value('pos.tax_rate'),
                'label' => $value('pos.tax_label'),
            ],
            'service' => [
                'inherit' => ! isset($own['pos.service_charge_rate']) && ! isset($own['pos.service_charge_dine_in_only']),
                'rate' => $value('pos.service_charge_rate'),
                'dine_in_only' => $value('pos.service_charge_dine_in_only') === '1',
            ],
            'payments' => [
                'inherit' => ! isset($own['pos.payment_methods']),
                'methods' => array_values(array_filter(is_array($methods) ? $methods : [], fn ($method) => PaymentMethod::tryFrom((string) $method) !== null)),
            ],
            'receipt' => [
                'inherit' => ! isset($own['pos.receipt_width']) && ! isset($own['pos.receipt_header']) && ! isset($own['pos.receipt_footer']) && ! isset($own['pos.auto_print']),
                'width' => $value('pos.receipt_width'),
                'header' => $value('pos.receipt_header'),
                'footer' => $value('pos.receipt_footer'),
                'auto_print' => $value('pos.auto_print') === '1',
            ],
            'qris' => [
                'inherit' => ! isset($own['pos.qris_payload']),
                'payload' => $own['pos.qris_payload'] ?? '',
            ],
            'rules' => [
                'inherit' => self::inherits($own, 'rules'),
                'allow_credit' => $value('pos.allow_credit') === '1',
                'allow_negative_stock' => $value('pos.allow_negative_stock') === '1',
                'quick_cash' => PosSettings::parseQuickCash($value('pos.quick_cash')),
            ],
            'pharmacy' => [
                'inherit' => self::inherits($own, 'pharmacy'),
                'prescription_mode' => $value('pos.prescription_mode') === 'warn' ? 'warn' : 'strict',
                'allow_controlled_drugs' => $value('pos.allow_controlled_drugs') === '1',
                'block_expired_sale' => $value('pos.block_expired_sale') === '1',
                'near_expiry_discount_percent' => $value('pos.near_expiry_discount_percent'),
                'near_expiry_discount_days' => $value('pos.near_expiry_discount_days'),
            ],
        ];
    }

    /**
     * Simpan bagian yang dikirim ($sections dalam bentuk sections()). Bagian dengan inherit true
     * menghapus penimpaannya sehingga outlet kembali mengikuti nilai toko. Tunai selalu aktif.
     *
     * @param  array<string, array<string, mixed>>  $sections
     */
    public static function applySections(int $outletId, array $sections): void
    {
        if (isset($sections['tax'])) {
            $tax = $sections['tax'];
            $inherit = (bool) ($tax['inherit'] ?? false);
            self::putMany($outletId, [
                'pos.tax_enabled' => $inherit ? null : (! empty($tax['enabled']) ? '1' : '0'),
                'pos.tax_rate' => $inherit ? null : (string) ($tax['rate'] ?? '0'),
                'pos.tax_label' => $inherit ? null : (string) ($tax['label'] ?? 'PPN'),
            ]);
        }

        if (isset($sections['service'])) {
            $service = $sections['service'];
            $inherit = (bool) ($service['inherit'] ?? false);
            self::putMany($outletId, [
                'pos.service_charge_rate' => $inherit ? null : (string) (float) ($service['rate'] ?? '0'),
                'pos.service_charge_dine_in_only' => $inherit ? null : (! empty($service['dine_in_only']) ? '1' : '0'),
            ]);
        }

        if (isset($sections['payments'])) {
            $payments = $sections['payments'];
            self::put($outletId, 'pos.payment_methods', ($payments['inherit'] ?? false) ? null : json_encode(array_values(array_unique([PaymentMethod::Cash->value, ...($payments['methods'] ?? [])]))));
        }

        if (isset($sections['receipt'])) {
            $receipt = $sections['receipt'];
            $inherit = (bool) ($receipt['inherit'] ?? false);
            self::putMany($outletId, [
                'pos.receipt_width' => $inherit ? null : (string) ($receipt['width'] ?? '58'),
                'pos.receipt_header' => $inherit ? null : trim((string) ($receipt['header'] ?? '')),
                'pos.receipt_footer' => $inherit ? null : trim((string) ($receipt['footer'] ?? '')),
                'pos.auto_print' => $inherit ? null : (! empty($receipt['auto_print']) ? '1' : '0'),
            ]);
        }

        if (isset($sections['qris'])) {
            $qris = $sections['qris'];
            self::put($outletId, 'pos.qris_payload', ($qris['inherit'] ?? false) ? null : trim((string) ($qris['payload'] ?? '')));
        }

        if (isset($sections['rules'])) {
            $rules = $sections['rules'];
            $inherit = (bool) ($rules['inherit'] ?? false);
            self::putMany($outletId, [
                'pos.allow_credit' => $inherit ? null : (! empty($rules['allow_credit']) ? '1' : '0'),
                'pos.allow_negative_stock' => $inherit ? null : (! empty($rules['allow_negative_stock']) ? '1' : '0'),
                'pos.quick_cash' => $inherit ? null : json_encode(array_values(array_filter(array_map('intval', (array) ($rules['quick_cash'] ?? [])), fn (int $value) => $value > 0))),
            ]);
        }

        if (isset($sections['pharmacy'])) {
            $pharmacy = $sections['pharmacy'];
            $inherit = (bool) ($pharmacy['inherit'] ?? false);
            self::putMany($outletId, [
                'pos.prescription_mode' => $inherit ? null : (($pharmacy['prescription_mode'] ?? 'strict') === 'warn' ? 'warn' : 'strict'),
                'pos.allow_controlled_drugs' => $inherit ? null : (! empty($pharmacy['allow_controlled_drugs']) ? '1' : '0'),
                'pos.block_expired_sale' => $inherit ? null : (! empty($pharmacy['block_expired_sale']) ? '1' : '0'),
                'pos.near_expiry_discount_percent' => $inherit ? null : (string) (float) ($pharmacy['near_expiry_discount_percent'] ?? '0'),
                'pos.near_expiry_discount_days' => $inherit ? null : (string) (int) ($pharmacy['near_expiry_discount_days'] ?? '2'),
            ]);
        }
    }

    /**
     * @param  array<string, string>  $own
     */
    private static function inherits(array $own, string $section): bool
    {
        return array_intersect_key($own, array_flip(self::SECTION_KEYS[$section])) === [];
    }

    public static function forget(int $outletId): void
    {
        Cache::forget(self::cacheKey($outletId));
    }

    private static function cacheKey(int $outletId): string
    {
        return "outlet_settings.{$outletId}";
    }
}
