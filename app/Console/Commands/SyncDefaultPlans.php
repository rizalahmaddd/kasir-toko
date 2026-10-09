<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\SaasPlans;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncDefaultPlans extends Command
{
    protected $signature = 'saas:sync-plans
                            {--force : Timpa paket yang sudah ada di DB dengan nilai bawaan}
                            {--reset : Reset penuh: hapus paket legacy (seperti basic) dan setel ulang ke 4 paket resmi (trial, free, pro, lifetime)}';

    protected $description = 'Sync paket langganan ke database. Gunakan --reset untuk membersihkan paket lama (basic).';

    public function handle(): int
    {
        $officialPlans = SaasPlans::DEFAULT_PLANS;

        if ($this->option('reset')) {
            $this->warn('Melakukan reset paket ke 4 paket resmi (trial, free, pro, lifetime)...');

            // Jika ada tenant yang masih memakai plan 'basic', migrasikan ke 'free'
            $migratedTenants = Tenant::where('plan', 'basic')->update(['plan' => 'free']);
            if ($migratedTenants > 0) {
                $this->line("  Migrasi {$migratedTenants} toko dari paket 'basic' ke 'free'.");
            }

            SaasPlans::save($officialPlans);
            Cache::forget('settings.platform');

            $this->info('Berhasil mereset paket! Sekarang hanya ada 4 paket:');
            foreach ($officialPlans as $key => $p) {
                $this->line("  - {$key}: {$p['label']}");
            }

            return self::SUCCESS;
        }

        $configPlans = array_merge($officialPlans, config('saas.plans', []));
        // Buang 'basic' jika masih ada di config lama
        unset($configPlans['basic']);

        $currentPlans = SaasPlans::all();
        unset($currentPlans['basic']);

        $added = [];
        $skipped = [];

        foreach ($configPlans as $key => $plan) {
            if (! isset($currentPlans[$key]) || $this->option('force')) {
                $currentPlans[$key] = $plan;
                $added[] = $key;
            } else {
                $skipped[] = $key;
            }
        }

        SaasPlans::save($currentPlans);
        Cache::forget('settings.platform');

        if (empty($added)) {
            $this->info('Semua paket sudah sesuai. Tidak ada perubahan baru.');
            $this->line('  Paket aktif: '.implode(', ', array_keys($currentPlans)));

            return self::SUCCESS;
        }

        $this->info('Paket berhasil di-sync ke database:');
        foreach ($added as $key) {
            $this->line("  + {$key}: ".($currentPlans[$key]['label'] ?? $key));
        }

        return self::SUCCESS;
    }
}
