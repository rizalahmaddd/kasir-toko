<?php

namespace App\Jobs;

use App\Models\BackupSchedule;
use App\Models\User;
use App\Notifications\BackupFinishedNotification;
use App\Services\BackupService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Backup di worker antrean, dipicu manual dari halaman Backup & Restore atau oleh jadwal
 * otomatis. Hasilnya dikabarkan lewat notifikasi lonceng, jadi pengguna tidak perlu menunggu.
 */
class CreateBackup implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Penanda di cache supaya halaman backup tahu masih ada backup manual yang antre/berjalan. */
    public const PENDING_KEY = 'backup:pending';

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $scope = 'database',
        public ?int $requestedBy = null,
        public ?int $scheduleId = null,
        public bool $includeSecrets = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->scheduleId ? "schedule-{$this->scheduleId}" : 'manual';
    }

    public function handle(BackupService $backups): void
    {
        $schedule = $this->schedule();

        if ($this->scheduleId && ! $schedule) {
            return;
        }

        if (! $schedule) {
            Cache::put(self::PENDING_KEY, ['scope' => $this->scope, 'since' => now()->toIso8601String()], $this->uniqueFor);
        }

        try {
            // Antre di belakang restore atau backup lain yang sedang jalan.
            $name = Cache::lock(BackupService::LOCK, $this->timeout)->block($this->timeout, fn () => $backups->create(
                $this->scope,
                $schedule?->filePrefix() ?? 'manual',
                $this->includeSecrets,
            ));

            $pruned = $schedule ? $backups->prune($schedule->filePrefix(), $schedule->keep) : [];
        } finally {
            if (! $schedule) {
                Cache::forget(self::PENDING_KEY);
            }
        }

        $schedule?->update(['last_run_at' => now(), 'last_status' => 'success', 'last_file' => $name, 'last_error' => null]);

        activity('settings')->causedBy($this->requester())
            ->withProperties(['file' => $name, 'scope' => $this->scope, 'schedule_id' => $this->scheduleId, 'pruned' => $pruned])
            ->log($schedule ? "Backup terjadwal \"{$schedule->name}\" dibuat: {$name}." : "Backup dibuat: {$name}.");

        Notification::send($this->recipients(), new BackupFinishedNotification($this->scope, $name, $schedule?->name));
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception?->getMessage() ?: 'Proses dihentikan worker.';
        $schedule = $this->schedule();

        if (! $schedule) {
            Cache::forget(self::PENDING_KEY);
        }

        $schedule?->update(['last_run_at' => now(), 'last_status' => 'failed', 'last_error' => $error]);

        Notification::send($this->recipients(), new BackupFinishedNotification($this->scope, null, $schedule?->name, $error));
    }

    /**
     * Backup manual dikabarkan ke yang memicunya; backup terjadwal ke semua superadmin.
     *
     * @return Collection<int, User>
     */
    private function recipients(): Collection
    {
        $requester = $this->requester();

        return $requester?->isSuperAdmin()
            ? collect([$requester])
            : User::whereHas('roles', fn ($query) => $query->where('name', 'superadmin'))->get();
    }

    private function requester(): ?User
    {
        return $this->requestedBy ? User::find($this->requestedBy) : null;
    }

    private function schedule(): ?BackupSchedule
    {
        return $this->scheduleId ? BackupSchedule::find($this->scheduleId) : null;
    }
}
