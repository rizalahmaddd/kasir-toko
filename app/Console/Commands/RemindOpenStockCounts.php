<?php

namespace App\Console\Commands;

use App\Models\StockCount;
use App\Models\Tenant;
use App\Notifications\StockCountOpenReminderNotification;
use App\Services\Pos\StockCountNotifier;
use App\Support\CurrentTenant;
use App\Support\Features;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class RemindOpenStockCounts extends Command
{
    public const OPEN_DAYS = 3;

    protected $signature = 'stock:remind-open-counts';

    protected $description = 'Ingatkan pengelola opname tentang stok opname yang dibiarkan terbuka lebih dari 3 hari.';

    public function handle(CurrentTenant $currentTenant, StockCountNotifier $notifier): int
    {
        $sent = 0;
        $tenantIds = StockCount::query()->withoutGlobalScopes()->open()->where('started_at', '<', now()->subDays(self::OPEN_DAYS))->distinct()->pluck('tenant_id');

        Tenant::query()->whereIn('id', $tenantIds)->each(function (Tenant $tenant) use ($currentTenant, $notifier, &$sent) {
            $currentTenant->run($tenant, function () use ($notifier, &$sent) {
                if (! Features::enabled('inventory.opname')) {
                    return;
                }

                StockCount::query()->withoutGlobalScopes()->where('tenant_id', app(CurrentTenant::class)->id())->open()
                    ->where('started_at', '<', now()->subDays(self::OPEN_DAYS))
                    ->each(function (StockCount $count) use ($notifier, &$sent) {
                        $recipients = $notifier->managers($count);

                        if ($recipients->isNotEmpty()) {
                            Notification::send($recipients, new StockCountOpenReminderNotification($count));
                            $sent++;
                        }
                    });
            });
        });

        $this->info("Pengingat opname terbuka dikirim untuk {$sent} dokumen.");

        return self::SUCCESS;
    }
}
