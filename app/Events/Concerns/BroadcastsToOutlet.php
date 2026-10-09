<?php

namespace App\Events\Concerns;

use App\Support\CurrentOutlet;
use App\Support\CurrentTenant;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Event yang terjadi di satu outlet: siaran ke channel outlet itu, dan juga ke channel toko bila $alsoToDashboard
 * supaya tampilan gabungan (dashboard, laporan semua outlet) tetap ikut segar. Kelas pemakai WAJIB implements
 * ShouldRescue, sama seperti BroadcastsToDashboard.
 */
trait BroadcastsToOutlet
{
    abstract protected function broadcastOutletId(): ?int;

    protected function alsoToDashboard(): bool
    {
        return true;
    }

    public function broadcastOn(): array
    {
        $tenantId = app(CurrentTenant::class)->id();
        $outletId = $this->broadcastOutletId();

        if ($tenantId === null) {
            return [];
        }

        return array_values(array_filter([
            $this->alsoToDashboard() ? new PrivateChannel(app(CurrentTenant::class)->dashboardChannel()) : null,
            $outletId !== null ? new PrivateChannel(CurrentOutlet::channelFor($tenantId, $outletId)) : null,
        ]));
    }
}
