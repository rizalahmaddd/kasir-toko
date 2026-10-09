<?php

namespace App\Jobs;

use App\Enums\StockCountStatus;
use App\Models\Scopes\OutletAccessScope;
use App\Models\StockCount;
use App\Models\User;
use App\Services\Pos\StockCountPoster;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Menyelesaikan opname besar di worker antrean. Baris yang sudah diproses dilewati, jadi job yang gagal
 * di tengah aman diulang (otomatis atau lewat tombol "Lanjutkan pemrosesan").
 */
class PostStockCount implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    public function __construct(public int $stockCountId, public int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->stockCountId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(StockCountPoster $poster): void
    {
        $count = StockCount::query()->withoutGlobalScope(OutletAccessScope::class)->find($this->stockCountId);
        $user = User::query()->find($this->userId);

        if ($count === null || $user === null || $count->status !== StockCountStatus::Posting) {
            return;
        }

        $poster->run($count, $user);
    }
}
