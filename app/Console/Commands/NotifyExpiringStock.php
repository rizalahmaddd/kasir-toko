<?php

namespace App\Console\Commands;

use App\Models\Outlet;
use App\Models\ProductBatch;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ProductsExpiringNotification;
use App\Support\CurrentTenant;
use App\Support\Features;
use App\Support\PosSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class NotifyExpiringStock extends Command
{
    protected $signature = 'stock:notify-expiring';

    protected $description = 'Kirim ringkasan batch yang sudah/hampir kedaluwarsa ke pengelola stok toko Pro yang memakai Batch & Kedaluwarsa.';

    public function handle(CurrentTenant $currentTenant): int
    {
        $sent = 0;

        Tenant::query()->whereIn('id', ProductBatch::query()->withoutGlobalScopes()->whereNotNull('expires_at')->where('quantity', '>', 0)->distinct()->select('tenant_id'))->each(function (Tenant $tenant) use ($currentTenant, &$sent) {
            if (! $tenant->isPro()) {
                return;
            }

            $currentTenant->run($tenant, function () use ($tenant, &$sent) {
                if (! Features::enabled('business.batch-expiry')) {
                    return;
                }

                $days = PosSettings::expiryWarningDays();
                $outletIds = Outlet::query()->pluck('id')->filter(fn (int $id) => Features::enabledAt('business.batch-expiry', $id))->values();
                $live = fn () => ProductBatch::query()->whereIn('outlet_id', $outletIds)->where('quantity', '>', 0)->whereNotNull('expires_at')->whereHas('product', fn ($query) => $query->where('track_batch', true));
                $expired = $live()->whereDate('expires_at', '<', today())->count();
                $soon = $live()->whereDate('expires_at', '>=', today())->whereDate('expires_at', '<=', today()->addDays($days))->count();

                if ($expired === 0 && $soon === 0) {
                    return;
                }

                $recipients = User::query()->where('tenant_id', $tenant->id)->get()->filter(fn (User $user) => $user->isSuperAdmin() || $user->can('inventory.manage'));
                Notification::send($recipients, new ProductsExpiringNotification($tenant->id, $expired, $soon, $days));
                $sent++;
            });
        });

        $this->info("Peringatan kedaluwarsa dikirim ke {$sent} toko.");

        return self::SUCCESS;
    }
}
