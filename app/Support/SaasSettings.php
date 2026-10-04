<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\Tenant;

/**
 * Pengaturan layanan yang diubah admin platform dari panel. Disimpan sebagai Setting tanpa
 * tenant; nilai bawaannya tetap dari config/saas.php supaya instalasi baru tidak perlu diisi dulu.
 */
class SaasSettings
{
    public const REGISTRATION_OPEN = 'saas.registration_open';

    public const TRIAL_DAYS = 'saas.trial_days';

    public const SUPPORT_CONTACT = 'saas.support_contact';

    public const PAYMENT_INSTRUCTIONS = 'saas.payment_instructions';

    public static function registrationOpen(): bool
    {
        return Setting::platform(self::REGISTRATION_OPEN, '1') === '1';
    }

    public static function trialDays(): int
    {
        return (int) (Setting::platform(self::TRIAL_DAYS) ?? config('saas.trial_days'));
    }

    /**
     * Kontak admin layanan (WhatsApp/email) yang ditampilkan ke toko yang masa aktifnya habis.
     */
    public static function supportContact(): ?string
    {
        return Setting::platform(self::SUPPORT_CONTACT) ?: null;
    }

    public static function paymentInstructions(): ?string
    {
        return Setting::platform(self::PAYMENT_INSTRUCTIONS) ?: null;
    }

    /**
     * Yang ditunjukkan ke toko terblokir supaya bisa memperpanjang: kontak, cara bayar, dan paket
     * berbayar yang harganya diisi. Dipakai halaman /langganan dan API (auth/me, respons 402).
     *
     * @return array{contact: ?string, payment_instructions: ?string, plans: list<array{key: string, label: string, price: int}>}
     */
    public static function renewalInfo(): array
    {
        return [
            'contact' => self::supportContact(),
            'payment_instructions' => self::paymentInstructions(),
            'plans' => collect(SaasPlans::all())
                ->except([Tenant::PLAN_TRIAL, Tenant::PLAN_FREE])
                ->filter(fn (array $plan) => ($plan['price'] ?? 0) > 0)
                ->map(fn (array $plan, string $key) => ['key' => $key, 'label' => $plan['label'], 'price' => $plan['price']])
                ->values()
                ->all(),
        ];
    }
}
