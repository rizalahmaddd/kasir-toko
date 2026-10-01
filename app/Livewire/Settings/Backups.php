<?php

namespace App\Livewire\Settings;

use App\Jobs\CreateBackup;
use App\Models\BackupSchedule;
use App\Services\BackupService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

#[Layout('layouts.app', ['heading' => 'Backup & Restore'])]
#[Title('Backup & Restore')]
class Backups extends Component
{
    use WithFileUploads;

    /** Backup dianggap basi kalau yang terakhir lebih tua dari ini. */
    private const STALE_AFTER_HOURS = 26;

    public string $scope = 'database';

    public bool $includeSecrets = false;

    #[Url(as: 'jenis', except: 'all')]
    public string $scopeFilter = 'all';

    public ?TemporaryUploadedFile $upload = null;

    public ?string $restoring = null;

    public string $password = '';

    /** @var array{type: 'file'|'schedule', key: string}|null */
    public ?array $deleting = null;

    public ?int $editingScheduleId = null;

    public string $scheduleName = '';

    public string $scheduleScope = 'database';

    public string $frequency = 'daily';

    /** @var list<string> */
    public array $times = ['02:00'];

    /** @var list<int|string> */
    public array $weekdays = [];

    public ?int $monthDay = 1;

    public int $keep = 7;

    public bool $scheduleIncludeSecrets = false;

    public bool $scheduleActive = true;

    public function mount(): void
    {
        $this->authorizeSuperAdmin();

        if ($this->scopeFilter !== 'all' && ! array_key_exists($this->scopeFilter, BackupService::SCOPES)) {
            $this->scopeFilter = 'all';
        }
    }

    public function createBackup(): void
    {
        $this->authorizeSuperAdmin();
        $this->validate(['scope' => ['required', Rule::in(array_keys(BackupService::SCOPES))]]);

        if (Cache::has(CreateBackup::PENDING_KEY)) {
            $this->dispatch('notify', message: __('Backup sebelumnya masih diproses. Tunggu notifikasinya dulu.'), type: 'error');

            return;
        }

        Cache::put(CreateBackup::PENDING_KEY, ['scope' => $this->scope, 'since' => now()->toIso8601String()], 3600);
        CreateBackup::dispatch($this->scope, Auth::id(), includeSecrets: $this->scope !== 'database' && $this->includeSecrets);

        $this->dispatch('notify', message: __('Backup berjalan di latar belakang. Notifikasi masuk ke lonceng begitu selesai.'), type: 'success');
    }

    public function openScheduleModal(?int $id = null): void
    {
        $this->authorizeSuperAdmin();
        $this->resetValidation();
        $this->resetScheduleForm();

        if ($schedule = $id ? BackupSchedule::findOrFail($id) : null) {
            $this->editingScheduleId = $schedule->id;
            $this->scheduleName = $schedule->name;
            $this->scheduleScope = $schedule->scope;
            $this->frequency = $schedule->frequency;
            $this->times = $schedule->times;
            $this->weekdays = array_map(strval(...), $schedule->weekdays ?? []);
            $this->monthDay = $schedule->month_day ?? 1;
            $this->keep = $schedule->keep;
            $this->scheduleIncludeSecrets = $schedule->include_secrets;
            $this->scheduleActive = $schedule->is_active;
        }

        $this->dispatch('open-modal', 'schedule-form');
    }

    public function closeModal(): void
    {
        $this->resetScheduleForm();
        $this->resetValidation();
        $this->dispatch('close-modal', 'schedule-form');
    }

    public function addTime(): void
    {
        $this->times[] = '';
    }

    public function removeTime(int $index): void
    {
        if (count($this->times) > 1) {
            unset($this->times[$index]);
            $this->times = array_values($this->times);
        }
    }

    public function saveSchedule(): void
    {
        $this->authorizeSuperAdmin();

        $this->validate([
            'scheduleName' => ['required', 'string', 'max:100'],
            'scheduleScope' => ['required', Rule::in(array_keys(BackupService::SCOPES))],
            'frequency' => ['required', Rule::in(array_keys(BackupSchedule::FREQUENCIES))],
            'times' => ['required', 'array', 'min:1', 'max:24'],
            'times.*' => ['required', 'date_format:H:i', 'distinct'],
            'weekdays' => ['exclude_unless:frequency,weekly', 'required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'monthDay' => ['exclude_unless:frequency,monthly', 'required', 'integer', 'between:1,28'],
            'keep' => ['required', 'integer', 'min:1', 'max:365'],
        ], [
            'weekdays.required' => __('Pilih minimal satu hari.'),
            'times.*.distinct' => __('Jam ini sudah ada di daftar.'),
            'times.*.date_format' => __('Format jam harus JJ:MM.'),
        ], [
            'scheduleName' => 'nama jadwal',
            'scheduleScope' => 'jenis backup',
            'frequency' => 'frekuensi',
            'times' => 'jam',
            'times.*' => 'jam',
            'monthDay' => 'tanggal',
            'keep' => 'jumlah disimpan',
        ]);

        $attributes = [
            'name' => $this->scheduleName,
            'scope' => $this->scheduleScope,
            'frequency' => $this->frequency,
            'times' => collect($this->times)->unique()->sort()->values()->all(),
            'weekdays' => $this->frequency === 'weekly' ? collect($this->weekdays)->map(fn ($day) => (int) $day)->unique()->sort()->values()->all() : null,
            'month_day' => $this->frequency === 'monthly' ? $this->monthDay : null,
            'keep' => $this->keep,
            'include_secrets' => $this->scheduleScope !== 'database' && $this->scheduleIncludeSecrets,
            'is_active' => $this->scheduleActive,
        ];

        $schedule = $this->editingScheduleId
            ? tap(BackupSchedule::findOrFail($this->editingScheduleId))->update($attributes)
            : BackupSchedule::create($attributes + ['created_by' => Auth::id()]);

        $this->dispatch('notify', message: __('Jadwal ":name" tersimpan: :when.', ['name' => $schedule->name, 'when' => $schedule->describe()]), type: 'success');
        $this->closeModal();
    }

    public function toggleSchedule(int $id): void
    {
        $this->authorizeSuperAdmin();

        $schedule = BackupSchedule::findOrFail($id);
        $schedule->update(['is_active' => ! $schedule->is_active]);

        $this->dispatch('notify', message: $schedule->is_active ? __('Jadwal ":name" diaktifkan.', ['name' => $schedule->name]) : __('Jadwal ":name" dijeda.', ['name' => $schedule->name]), type: 'success');
    }

    public function runScheduleNow(int $id): void
    {
        $this->authorizeSuperAdmin();

        $schedule = BackupSchedule::findOrFail($id);
        CreateBackup::dispatch($schedule->scope, Auth::id(), $schedule->id, $schedule->include_secrets);

        $this->dispatch('notify', message: __('Jadwal ":name" dijalankan sekarang di latar belakang.', ['name' => $schedule->name]), type: 'success');
    }

    public function uploadBackup(BackupService $backups): void
    {
        $this->authorizeSuperAdmin();
        $this->validate(['upload' => ['required', 'file', 'max:12288']]);

        try {
            $name = $backups->store($this->upload);
        } catch (RuntimeException $e) {
            $this->addError('upload', $e->getMessage());

            return;
        } finally {
            $this->upload = null;
        }

        activity('settings')->causedBy(Auth::user())
            ->withProperties(['file' => $name])
            ->log("File backup diunggah: {$name}.");

        $this->dispatch('notify', message: __('File backup diunggah dan masuk ke daftar.'), type: 'success');
    }

    public function confirmRestore(string $name): void
    {
        $this->authorizeSuperAdmin();
        $this->resetErrorBag();
        $this->restoring = $name;
        $this->password = '';
        $this->dispatch('open-modal', 'confirm-restore');
    }

    public function cancelRestore(): void
    {
        $this->reset('restoring', 'password');
        $this->resetErrorBag();
    }

    public function restore(BackupService $backups): void
    {
        $this->authorizeSuperAdmin();
        $this->validate(['password' => ['required', 'current_password']], [
            'password.current_password' => __('Password salah.'),
        ]);

        $name = (string) $this->restoring;
        $safetyBackup = $this->withLock(fn () => $backups->restore($name));

        if ($safetyBackup === null) {
            return;
        }

        Cache::flush();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        activity('settings')->causedBy(Auth::user())
            ->withProperties(['file' => $name, 'safety_backup' => $safetyBackup])
            ->log("Database dipulihkan dari backup {$name}.");

        session()->flash('notify', [
            'message' => __('Database dipulihkan dari :file. Kondisi sebelumnya tersimpan sebagai :safety.', ['file' => $name, 'safety' => $safetyBackup]),
            'type' => 'success',
        ]);

        $this->redirectRoute('settings.backups');
    }

    public function confirmDelete(string $name): void
    {
        $this->authorizeSuperAdmin();
        $this->deleting = ['type' => 'file', 'key' => $name];
        $this->dispatch('open-modal', 'confirm-delete');
    }

    public function confirmDeleteSchedule(int $id): void
    {
        $this->authorizeSuperAdmin();
        $this->deleting = ['type' => 'schedule', 'key' => (string) $id];
        $this->dispatch('open-modal', 'confirm-delete');
    }

    public function cancelDelete(): void
    {
        $this->deleting = null;
    }

    public function delete(BackupService $backups): void
    {
        $this->authorizeSuperAdmin();

        if ($this->deleting === null) {
            return;
        }

        if ($this->deleting['type'] === 'schedule') {
            $schedule = BackupSchedule::findOrFail((int) $this->deleting['key']);
            $schedule->delete();
            $message = __('Jadwal ":name" dihapus. File backup yang sudah dibuat tetap tersimpan.', ['name' => $schedule->name]);
        } else {
            try {
                $backups->delete($this->deleting['key']);
            } catch (RuntimeException $e) {
                $this->dispatch('notify', message: $e->getMessage(), type: 'error');

                return;
            }

            activity('settings')->causedBy(Auth::user())
                ->withProperties(['file' => $this->deleting['key']])
                ->log("File backup dihapus: {$this->deleting['key']}.");

            $message = __('File backup dihapus.');
        }

        $this->deleting = null;
        $this->dispatch('close-modal', 'confirm-delete');
        $this->dispatch('notify', message: $message, type: 'success');
    }

    public function render(BackupService $backups): View
    {
        $files = $backups->list();
        $schedules = BackupSchedule::query()->orderByDesc('is_active')->orderBy('name')->get();
        $scheduleNames = $schedules->mapWithKeys(fn (BackupSchedule $schedule) => [$schedule->filePrefix() => $schedule->name]);
        $lastBackup = $files->first(fn (array $file) => ! in_array($file['origin'], ['pre-restore', 'upload'], true));
        $nextRun = $schedules
            ->map(fn (BackupSchedule $schedule) => ['schedule' => $schedule, 'at' => $schedule->nextRunAt(now())])
            ->filter(fn (array $run) => $run['at'] !== null)
            ->sortBy('at')
            ->first();
        $pending = Cache::get(CreateBackup::PENDING_KEY);
        $freeSpace = @disk_free_space(Storage::disk('local')->path(''));

        return view('livewire.settings.backups', [
            'files' => $this->scopeFilter === 'all' ? $files : $files->where('scope', $this->scopeFilter)->values(),
            'scopeCounts' => $files->countBy('scope'),
            'totalFiles' => $files->count(),
            'totalSize' => $files->sum('size'),
            'freeSpace' => $freeSpace === false ? null : $freeSpace,
            'lastBackup' => $lastBackup,
            'health' => match (true) {
                $lastBackup === null => 'none',
                $lastBackup['created_at']->lt(now()->subHours(self::STALE_AFTER_HOURS)) => 'stale',
                default => 'fresh',
            },
            'schedules' => $schedules,
            'scheduleNames' => $scheduleNames,
            'nextRun' => $nextRun,
            'pending' => $pending ? ['scope' => $pending['scope'], 'since' => Carbon::parse($pending['since'])] : null,
            'deletingLabel' => match ($this->deleting['type'] ?? null) {
                'schedule' => 'jadwal "'.($schedules->firstWhere('id', (int) $this->deleting['key'])?->name ?? '').'"',
                'file' => 'file '.$this->deleting['key'],
                default => '',
            },
        ]);
    }

    protected function resetScheduleForm(): void
    {
        $this->reset('editingScheduleId', 'scheduleName', 'scheduleScope', 'frequency', 'times', 'weekdays', 'monthDay', 'keep', 'scheduleIncludeSecrets', 'scheduleActive');
    }

    /**
     * Restore tidak boleh jalan bersamaan dengan backup (dua tab / worker antrean), karena restore
     * men-drop tabel yang sedang dibaca proses backup.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    protected function withLock(callable $callback): mixed
    {
        $lock = Cache::lock(BackupService::LOCK, 3600);

        if (! $lock->get()) {
            $this->dispatch('notify', message: __('Ada backup yang sedang dibuat. Coba restore lagi setelah selesai.'), type: 'error');

            return null;
        }

        try {
            return $callback();
        } catch (RuntimeException $e) {
            $this->dispatch('close-modal', 'confirm-restore');
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return null;
        } finally {
            $lock->forceRelease();
        }
    }

    protected function authorizeSuperAdmin(): void
    {
        abort_unless(Auth::user()->isSuperAdmin(), 403);
    }
}
