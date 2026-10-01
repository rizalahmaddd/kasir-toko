<?php

use App\Jobs\CreateBackup;
use App\Livewire\Settings\Backups;
use App\Models\BackupSchedule;
use App\Models\User;
use App\Notifications\BackupFinishedNotification;
use App\Services\BackupService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->appRoot = storage_path('framework/testing/app-root-'.Str::random(8));
    File::ensureDirectoryExists($this->appRoot.'/app/Models');
    File::ensureDirectoryExists($this->appRoot.'/vendor/laravel');
    File::ensureDirectoryExists($this->appRoot.'/storage/app/public');
    File::put($this->appRoot.'/app/Models/Order.php', '<?php // order');
    File::put($this->appRoot.'/vendor/laravel/big.php', 'vendor');
    File::put($this->appRoot.'/storage/app/public/logo.png', 'png');
    File::put($this->appRoot.'/.env', 'APP_KEY=rahasia');
    File::put($this->appRoot.'/.env.example', 'APP_KEY=');

    app()->instance(BackupService::class, new BackupService(filesRoot: $this->appRoot));
});

afterEach(function () {
    File::deleteDirectory($this->appRoot);
});

function backupService(): BackupService
{
    return app(BackupService::class);
}

/**
 * @return list<string>
 */
function zipEntries(string $name): array
{
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path(BackupService::DIRECTORY.'/'.$name));
    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[] = $zip->getNameIndex($i);
    }

    $zip->close();

    return $entries;
}

test('only superadmin can open the backup page and download backups', function () {
    actingAsSuperAdmin();
    $name = backupService()->create();
    $this->get(route('settings.backups'))->assertOk()->assertSee($name);

    actingAsAdmin();
    $this->get(route('settings.backups'))->assertForbidden();
    $this->get(route('settings.backups.download', $name))->assertForbidden();
    Livewire::test(Backups::class)->assertForbidden();
});

test('superadmin restores the database after data changed', function () {
    actingAsSuperAdmin();
    $tricky = User::factory()->create(['name' => "O'Brien; DROP TABLE users; -- \\ \n baris dua"]);
    $backup = backupService()->create();

    $tricky->delete();
    $added = User::factory()->create();

    Livewire::test(Backups::class)
        ->call('confirmRestore', $backup)
        ->set('password', 'password')
        ->call('restore')
        ->assertHasNoErrors()
        ->assertRedirect(route('settings.backups'));

    expect(User::find($tricky->id)?->name)->toBe($tricky->name)
        ->and(User::find($added->id))->toBeNull()
        ->and(backupService()->list()->contains('origin', 'pre-restore'))->toBeTrue();
});

test('restore requires the superadmin password', function () {
    actingAsSuperAdmin();
    $backup = backupService()->create();
    $added = User::factory()->create();

    Livewire::test(Backups::class)
        ->call('confirmRestore', $backup)
        ->set('password', 'salah')
        ->call('restore')
        ->assertHasErrors('password');

    expect(User::find($added->id))->not->toBeNull();
});

test('files backup packs application code but skips rebuildable folders and secrets by default', function () {
    $name = backupService()->create('files');
    $entries = zipEntries($name);

    expect($name)->toStartWith('manual-files-')->toEndWith('.zip')
        ->and($entries)->toContain('backup-manifest.json', 'files/app/Models/Order.php', 'files/storage/app/public/logo.png', 'files/.env.example')
        ->not->toContain('files/vendor/laravel/big.php', 'files/.env', 'database.sql.gz')
        ->and(zipEntries(backupService()->create('files', includeSecrets: true)))->toContain('files/.env');
});

test('full backup restores its database part and files backups cannot be restored here', function () {
    actingAsSuperAdmin();
    $full = backupService()->create('full');
    $filesOnly = backupService()->create('files');
    $added = User::factory()->create();

    expect(zipEntries($full))->toContain('database.sql.gz', 'files/app/Models/Order.php')
        ->and(backupService()->list()->firstWhere('name', $filesOnly)['restorable'])->toBeFalse();

    expect(fn () => backupService()->restore($filesOnly))->toThrow(RuntimeException::class, 'dipulihkan manual');

    backupService()->restore($full);

    expect(User::find($added->id))->toBeNull();
});

test('uploaded files must be backups made by this app', function () {
    actingAsSuperAdmin();

    Livewire::test(Backups::class)
        ->set('upload', UploadedFile::fake()->createWithContent('dump.sql', "DROP TABLE users;\n"))
        ->call('uploadBackup')
        ->assertHasErrors('upload');

    expect(backupService()->list())->toBeEmpty();

    $full = backupService()->create('full');
    $contents = Storage::disk('local')->get(BackupService::DIRECTORY.'/'.$full);

    Livewire::test(Backups::class)
        ->set('upload', UploadedFile::fake()->createWithContent('kantor pusat.zip', $contents))
        ->call('uploadBackup')
        ->assertHasNoErrors();

    expect(backupService()->list()->firstWhere('origin', 'upload'))
        ->scope->toBe('full')
        ->name->toEndWith('-kantor-pusat.zip');
});

test('backup file names cannot escape the backup directory', function () {
    actingAsSuperAdmin();
    Storage::disk('local')->put('secret.sql', '-- rahasia');

    $this->get(route('settings.backups.download', '..%2Fsecret.sql'))->assertNotFound();

    Livewire::test(Backups::class)
        ->call('confirmDelete', '../secret.sql')
        ->call('delete');

    Storage::disk('local')->assertExists('secret.sql');
});

test('manual backup is queued once with the chosen scope', function () {
    Queue::fake();
    $superadmin = actingAsSuperAdmin();

    Livewire::test(Backups::class)
        ->set('scope', 'full')
        ->set('includeSecrets', true)
        ->call('createBackup')
        ->assertSee('sedang diproses sejak')
        ->call('createBackup');

    Queue::assertPushed(CreateBackup::class, 1);
    Queue::assertPushed(fn (CreateBackup $job) => $job->scope === 'full' && $job->includeSecrets && $job->requestedBy === $superadmin->id && $job->scheduleId === null);
});

test('queued manual backup notifies only the superadmin who requested it', function () {
    Notification::fake();
    $superadmin = actingAsSuperAdmin();
    $otherSuperadmin = User::factory()->create()->assignRole('superadmin');
    Cache::put(CreateBackup::PENDING_KEY, ['scope' => 'database', 'since' => now()->toIso8601String()]);

    (new CreateBackup('database', $superadmin->id))->handle(backupService());

    expect(Cache::has(CreateBackup::PENDING_KEY))->toBeFalse();
    Notification::assertSentTo($superadmin, BackupFinishedNotification::class, fn ($notification) => str_starts_with($notification->file, 'manual-database-'));
    Notification::assertNotSentTo($otherSuperadmin, BackupFinishedNotification::class);
});

test('scheduled backup keeps only its own newest backups and records the result', function () {
    Notification::fake();
    $superadmins = collect([actingAsSuperAdmin(), User::factory()->create()->assignRole('superadmin')]);
    $schedule = BackupSchedule::factory()->create(['keep' => 2]);
    $other = BackupSchedule::factory()->create();

    $disk = Storage::disk('local');
    $existing = [
        "{$schedule->filePrefix()}-database-lama-1.sql.gz",
        "{$schedule->filePrefix()}-database-lama-2.sql.gz",
        "{$other->filePrefix()}-database-lama.sql.gz",
        'manual-database-lama.sql.gz',
    ];
    foreach ($existing as $i => $name) {
        $disk->put(BackupService::DIRECTORY.'/'.$name, 'x');
        touch($disk->path(BackupService::DIRECTORY.'/'.$name), now()->subDays(10 - $i)->getTimestamp());
    }

    (new CreateBackup('database', scheduleId: $schedule->id))->handle(backupService());

    $names = backupService()->list()->pluck('name');
    expect($names)->toHaveCount(4)
        ->not->toContain($existing[0])
        ->toContain($existing[1], $existing[2], $existing[3])
        ->and($schedule->fresh())
        ->last_status->toBe('success')
        ->last_file->toStartWith($schedule->filePrefix().'-database-');
    Notification::assertSentTo($superadmins, BackupFinishedNotification::class, fn ($notification) => $notification->scheduleName === $schedule->name);
});

test('a failed scheduled backup is recorded and every superadmin is told why', function () {
    Notification::fake();
    $superadmin = actingAsSuperAdmin();
    $schedule = BackupSchedule::factory()->create();

    (new CreateBackup('database', scheduleId: $schedule->id))->failed(new RuntimeException('Disk penuh'));

    expect($schedule->fresh())->last_status->toBe('failed')->last_error->toBe('Disk penuh');
    Notification::assertSentTo($superadmin, BackupFinishedNotification::class,
        fn ($notification) => $notification->file === null && str_contains($notification->toArray($superadmin)['message'], 'Disk penuh'));
});

test('superadmin creates a daily schedule with several times', function () {
    actingAsSuperAdmin();

    Livewire::test(Backups::class)
        ->call('openScheduleModal')
        ->set('scheduleName', 'Database harian')
        ->set('times.0', '20:00')
        ->call('addTime')
        ->set('times.1', '02:00')
        ->call('saveSchedule')
        ->assertHasNoErrors();

    $schedule = BackupSchedule::sole();

    expect($schedule->times)->toBe(['02:00', '20:00'])
        ->and($schedule->isDueAt(Carbon::parse('2026-09-28 02:00')))->toBeTrue()
        ->and($schedule->isDueAt(Carbon::parse('2026-09-28 20:00')))->toBeTrue()
        ->and($schedule->isDueAt(Carbon::parse('2026-09-28 08:00')))->toBeFalse()
        ->and($schedule->nextRunAt(Carbon::parse('2026-09-28 02:00'))->toDateTimeString())->toBe('2026-09-28 20:00:00');
});

test('schedule form rejects duplicate times and weekly schedules without days', function () {
    actingAsSuperAdmin();

    Livewire::test(Backups::class)
        ->set('scheduleName', 'Mingguan')
        ->set('frequency', 'weekly')
        ->set('times', ['02:00', '02:00'])
        ->call('saveSchedule')
        ->assertHasErrors(['weekdays', 'times.1']);

    expect(BackupSchedule::count())->toBe(0);
});

test('weekly and monthly schedules only run on their days', function () {
    $weekly = BackupSchedule::factory()->weekly([1, 5], ['23:30'])->make();
    $monthly = BackupSchedule::factory()->make(['frequency' => 'monthly', 'month_day' => 1, 'times' => ['01:00']]);

    expect($weekly->isDueAt(Carbon::parse('2026-10-02 23:30')))->toBeTrue()
        ->and($weekly->isDueAt(Carbon::parse('2026-10-01 23:30')))->toBeFalse()
        ->and($weekly->nextRunAt(Carbon::parse('2026-10-02 23:31'))->toDateTimeString())->toBe('2026-10-05 23:30:00')
        ->and($monthly->nextRunAt(Carbon::parse('2026-10-01 02:00'))->toDateTimeString())->toBe('2026-11-01 01:00:00');
});

test('scheduler dispatches only active schedules that are due this minute', function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-09-28 20:00:10'));
    $due = BackupSchedule::factory()->create(['times' => ['02:00', '20:00'], 'scope' => 'full', 'include_secrets' => true]);
    BackupSchedule::factory()->inactive()->create(['times' => ['20:00']]);
    BackupSchedule::factory()->create(['times' => ['21:00']]);

    Artisan::call('schedule:run');

    Queue::assertPushed(CreateBackup::class, 1);
    Queue::assertPushed(fn (CreateBackup $job) => $job->scheduleId === $due->id && $job->scope === 'full' && $job->includeSecrets);
});
