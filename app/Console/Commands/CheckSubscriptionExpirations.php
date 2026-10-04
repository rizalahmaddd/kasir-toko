<?php

namespace App\Console\Commands;

use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Notifications\SubscriptionExpiringNotification;
use Illuminate\Console\Command;

class CheckSubscriptionExpirations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'saas:check-expirations {--dry-run : Menjalankan simulasi tanpa mengirim notifikasi sebenarnya}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Memeriksa masa aktif tenant dan mengirim pengingat otomatis (H-7, H-3, H-1, H-0)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun ? 'Memulai pemeriksaan masa aktif tenant [SIMULASI]...' : 'Memulai pemeriksaan masa aktif tenant...');

        if (! $dryRun) {
            $cancelled = SubscriptionInvoice::autoCancelStale(72);
            if ($cancelled > 0) {
                $this->line("Membatalkan {$cancelled} tagihan pending kedaluwarsa.");
            }
        }

        $now = now()->startOfDay();
        $notifiedCount = 0;

        /** @var Tenant $tenant */
        foreach (Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->whereNotNull('plan')->cursor() as $tenant) {
            $endsAt = $tenant->accessEndsAt();

            if (! $endsAt) {
                continue;
            }

            $endsAtDay = $endsAt->copy()->startOfDay();
            $daysRemaining = (int) $now->diffInDays($endsAtDay, false);

            $stage = match ($daysRemaining) {
                7 => 'd7',
                3 => 'd3',
                1 => 'd1',
                0 => 'd0',
                default => null,
            };

            if (! $stage && $tenant->isInGracePeriod() && $tenant->daysLeftInGrace() === 1) {
                $stage = 'grace';
            }

            if ($stage) {
                $owner = $tenant->owner();

                if ($owner) {
                    $this->line("Tenant [{$tenant->name}] - Tahap: {$stage}, Sisa hari: {$daysRemaining}, Pemilik: {$owner->email}");

                    if (! $dryRun) {
                        $owner->notify(new SubscriptionExpiringNotification($tenant, $stage));
                    }

                    $notifiedCount++;
                }
            }
        }

        $this->info("Pemeriksaan selesai. Total pengingat terkirim: {$notifiedCount}");

        return self::SUCCESS;
    }
}
