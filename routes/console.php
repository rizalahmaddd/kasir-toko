<?php

use App\Jobs\CreateBackup;
use App\Models\BackupSchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Laravel\Sanctum\PersonalAccessToken;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Jadwal diatur superadmin di Pengaturan > Backup & Restore, jadi dicek tiap menit.
Schedule::call(function () {
    BackupSchedule::active()->get()
        ->filter(fn (BackupSchedule $schedule) => $schedule->isDueAt(now()))
        ->each(fn (BackupSchedule $schedule) => CreateBackup::dispatch($schedule->scope, scheduleId: $schedule->id, includeSecrets: $schedule->include_secrets));
})->everyMinute()->name('backup-schedules');

// Token mobile yang ditolak karena kedaluwarsa atau lama tidak dipakai (config/sanctum.php) dibuang dari database.
Schedule::command('sanctum:prune-expired --hours=24')->daily()->when(fn () => config('sanctum.expiration') !== null);
Schedule::call(function () {
    $cutoff = now()->subDays((int) config('sanctum.idle_days'));

    PersonalAccessToken::query()
        ->where(fn ($query) => $query->where('last_used_at', '<', $cutoff)->orWhere(fn ($unused) => $unused->whereNull('last_used_at')->where('created_at', '<', $cutoff)))
        ->delete();
})->daily()->when(fn () => (int) config('sanctum.idle_days') > 0)->name('prune-idle-api-tokens');

use App\Models\SubscriptionInvoice;

// Memeriksa tenant yang masa aktifnya mendekati kedaluwarsa dan mengirim pengingat otomatis
Schedule::command('saas:check-expirations')->dailyAt('08:00')->name('check-subscription-expirations');

// Otomatis membatalkan tagihan langganan pending yang telah melampaui 3x24 jam (72 jam)
Schedule::call(function () {
    SubscriptionInvoice::autoCancelStale(72);
})->hourly()->name('auto-cancel-stale-subscription-invoices');
