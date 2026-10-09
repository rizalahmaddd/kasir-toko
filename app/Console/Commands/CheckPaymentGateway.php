<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\SumoPodPaymentService;
use Illuminate\Console\Command;

class CheckPaymentGateway extends Command
{
    protected $signature = 'saas:check-payment';

    protected $description = 'Periksa status konfigurasi Payment Gateway SumoPod QRIS';

    public function handle(SumoPodPaymentService $service): int
    {
        $this->info('--- DIAGNOSA KONFIGURASI SUMOPOD QRIS ---');

        $envKey = getenv('SUMOPOD_API_KEY') ?: ($_ENV['SUMOPOD_API_KEY'] ?? null);
        $configKey = config('services.sumopod.api_key');
        $dbKey = Setting::platform('sumopod.api_key');
        $activeKey = $service->apiKey();
        $isConfigured = $service->isConfigured();

        $this->line('1. ENV SUMOPOD_API_KEY: '.($envKey ? substr($envKey, 0, 8).'...'.substr($envKey, -4) : '<KOSONG>'));
        $this->line('2. CONFIG services.sumopod.api_key: '.($configKey ? substr($configKey, 0, 8).'...'.substr($configKey, -4) : '<KOSONG>'));
        $this->line('3. DATABASE Setting sumopod.api_key: '.($dbKey ? substr($dbKey, 0, 8).'...'.substr($dbKey, -4) : '<KOSONG>'));
        $this->line('4. API URL: '.$service->apiUrl());
        $this->line('-----------------------------------------');

        if ($isConfigured) {
            $this->info('STATUS: AKTIF & SIAP DIGUNAKAN!');
            $this->line('Key aktif: '.substr($activeKey, 0, 8).'...'.substr($activeKey, -4));

            return self::SUCCESS;
        }

        $this->error('STATUS: BELUM AKTIF (isConfigured = false)');

        if ($envKey && ! $configKey) {
            $this->warn('PENTING: Nilai ada di .env tetapi tidak terbaca di config!');
            $this->warn('Hal ini disebabkan oleh CONFIG CACHE di server.');
            $this->line('Solusi: Jalankan "php artisan config:clear" di server.');
        } elseif (! $envKey && ! $configKey && ! $dbKey) {
            $this->warn('PENTING: Tidak ada API Key yang terdeteksi di .env, config, maupun database.');
            $this->line('Solusi:');
            $this->line('a. Buka web Admin SaaS -> Pengaturan Layanan -> Isi SumoPod API Key lalu Simpan, ATAU');
            $this->line('b. Tambahkan SUMOPOD_API_KEY di file .env server lalu jalankan "php artisan config:clear".');
        }

        return self::FAILURE;
    }
}
