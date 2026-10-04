<?php

namespace App\Notifications;

use App\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Kabar ke admin platform bahwa backup yang berjalan di antrean sudah selesai atau gagal.
 * Di-queue per channel supaya Reverb yang mati tidak ikut menggagalkan notifikasi lonceng
 * maupun job backupnya.
 */
class BackupFinishedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $scope,
        public ?string $file,
        public ?string $scheduleName = null,
        public ?string $error = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        $kind = 'Backup '.mb_strtolower(BackupService::SCOPES[$this->scope]['label'] ?? $this->scope)
            .($this->scheduleName ? " (jadwal {$this->scheduleName})" : '');

        return [
            'icon' => $this->file ? 'archive' : 'alert-triangle',
            'color' => $this->file ? 'emerald' : 'rose',
            'message' => $this->file
                ? "{$kind} selesai: {$this->file}. Siap diunduh."
                : "{$kind} gagal: {$this->error}",
            'url' => route('platform.backups'),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
