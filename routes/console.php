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

// Batch & Kedaluwarsa: ringkasan stok hampir kedaluwarsa (Pro) dan audit saldo batch vs stok.
Schedule::command('stock:notify-expiring')->dailyAt('07:00')->name('notify-expiring-stock');
Schedule::command('stock:verify-batches')->dailyAt('03:30')->name('verify-batch-stock');

// Resep: hapus foto yang melewati masa simpan pilihan toko (data resepnya tetap).
Schedule::command('pharmacy:prune-prescription-photos')->dailyAt('04:00')->name('prune-prescription-photos');

// Stok opname: ingatkan dokumen yang dibiarkan terbuka, karena hitungan basi dan bisa menahan stok masuk/keluar.
Schedule::command('stock:remind-open-counts')->dailyAt('08:30')->name('remind-open-stock-counts');
