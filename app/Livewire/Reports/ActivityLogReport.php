<?php

namespace App\Livewire\Reports;

use App\Listeners\LogAuthenticationActivity;
use App\Livewire\Concerns\WithDataTable;
use App\Livewire\Concerns\WithDateRangeFilter;
use App\Livewire\Concerns\WithRealtimeRefresh;
use App\Models\User;
use App\Support\Audit\AuditTrail;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

/**
 * Log Aktivitas (lewat App\Policies\ActivityPolicy): peristiwa bisnis, perubahan data
 * per baris beserta snapshot before/after, login, dan ekspor. Bisa ditelusuri per batch (satu aksi
 * pengguna) atau per data (riwayat lengkap satu baris).
 */
#[Layout('layouts.app', ['heading' => 'Log Aktivitas'])]
#[Title('Log Aktivitas')]
class ActivityLogReport extends Component
{
    use WithDataTable;
    use WithDateRangeFilter;
    use WithRealtimeRefresh;

    protected function realtimeEvents(): array
    {
        return ['customer.changed'];
    }

    /**
     * Label kategori untuk setiap log_name yang ditulis lewat activity('...') di aplikasi.
     *
     * @var array<string, string>
     */
    public const LOG_NAMES = [
        'settings' => 'Pengaturan',
        'roles' => 'Peran & Hak Akses',
        AuditTrail::LOG_NAME => 'Perubahan Data',
        LogAuthenticationActivity::LOG_NAME => 'Login & Sesi',
        'export' => 'Ekspor & Unduhan',
        'platform' => 'Pengelolaan Layanan',
    ];

    /**
     * Log teknis yang ditulis otomatis (perubahan data per baris, login, ekspor). Tidak ditampilkan
     * di feed dashboard supaya ringkasan peristiwa bisnis tidak tenggelam.
     *
     * @var list<string>
     */
    public const TECHNICAL_LOG_NAMES = [AuditTrail::LOG_NAME, LogAuthenticationActivity::LOG_NAME, 'export'];

    #[Url(history: true)]
    public string $logName = '';

    #[Url(history: true)]
    public string $event = '';

    #[Url(history: true)]
    public string $subjectType = '';

    #[Url(history: true)]
    public string $subjectId = '';

    #[Url(history: true)]
    public string $causerId = '';

    #[Url(history: true)]
    public string $batch = '';

    #[Url(history: true)]
    public string $search = '';

    public ?int $selectedActivityId = null;

    public function mount(): void
    {
        $this->authorizeView();
        $this->sortField = 'created_at';
        $this->sortDirection = 'desc';
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['logName', 'event', 'subjectType', 'subjectId', 'causerId', 'batch', 'search', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function showDetail(int $activityId): void
    {
        $this->selectedActivityId = $activityId;
        $this->dispatch('open-modal', 'activity-detail');
    }

    public function traceBatch(string $batchUuid): void
    {
        $this->resetTrace();
        $this->batch = $batchUuid;
        $this->dispatch('close-modal', 'activity-detail');
        $this->resetPage();
    }

    public function traceSubject(string $subjectType, string $subjectId): void
    {
        $this->resetTrace();
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->dispatch('close-modal', 'activity-detail');
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->resetTrace();
        $this->reset('logName', 'event', 'subjectType', 'causerId', 'search');
        $this->resetPage();
    }

    private function resetTrace(): void
    {
        $this->reset('batch', 'subjectId');
    }

    /**
     * Saat menelusuri satu batch atau satu data, rentang tanggal diabaikan supaya riwayatnya utuh.
     */
    public function isTracing(): bool
    {
        return $this->batch !== '' || ($this->subjectType !== '' && $this->subjectId !== '');
    }

    #[Computed]
    public function selectedActivity(): ?Activity
    {
        return $this->selectedActivityId ? $this->baseQuery()->with('causer', 'subject')->find($this->selectedActivityId) : null;
    }

    #[Computed]
    public function causerOptions(): array
    {
        return User::query()
            ->whereIn('id', $this->baseQuery()->where('causer_type', (new User)->getMorphClass())->select('causer_id')->distinct())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    protected function authorizeView(): void
    {
        abort_unless(Auth::user()->can('viewAny', Activity::class), 403);
    }

    /**
     * Titik awal semua query log; halaman turunan mempersempitnya (mis. log panel platform).
     */
    protected function baseQuery(): Builder
    {
        return Activity::query();
    }

    private function filteredQuery(): Builder
    {
        $query = $this->baseQuery()
            ->with('causer', 'subject')
            ->when(! $this->isTracing(), fn (Builder $query) => $query
                ->where('created_at', '>=', Carbon::parse($this->from)->startOfDay())
                ->where('created_at', '<=', Carbon::parse($this->to)->endOfDay()))
            ->when($this->logName, fn (Builder $query) => $query->where('log_name', $this->logName))
            ->when($this->event, fn (Builder $query) => $query->where('event', $this->event))
            ->when($this->subjectType, fn (Builder $query) => $query->where('subject_type', $this->subjectType))
            ->when($this->subjectType && $this->subjectId !== '', fn (Builder $query) => $query->where('subject_id', $this->subjectId))
            ->when($this->causerId === 'system', fn (Builder $query) => $query->whereNull('causer_id'))
            ->when($this->causerId !== '' && $this->causerId !== 'system', fn (Builder $query) => $query
                ->where('causer_type', (new User)->getMorphClass())
                ->where('causer_id', $this->causerId))
            ->when($this->batch, fn (Builder $query) => $query->where('batch_uuid', $this->batch))
            ->when(trim($this->search) !== '', function (Builder $query) {
                $term = '%'.trim($this->search).'%';

                $query->where(fn (Builder $query) => $query
                    ->where('description', 'like', $term)
                    ->orWhere('attribute_changes', 'like', $term)
                    ->orWhere('properties', 'like', $term)
                    ->orWhere('ip_address', 'like', $term));
            });

        $this->applySorting($query, [
            'created_at' => 'created_at',
            'log_name' => 'log_name',
            'event' => 'event',
            'description' => 'description',
        ], 'created_at', 'desc');

        return $query->orderByDesc('id');
    }

    public function export(string $format = 'xlsx')
    {
        $headers = ['Waktu', 'Kategori', 'Aksi', 'Data', 'ID Data', 'Deskripsi Aktivitas', 'Eksekutor', 'Alasan (Jika Ada)', 'Perubahan (JSON)', 'IP', 'Halaman', 'Batch'];

        $rows = $this->filteredQuery()->lazy()->map(function (Activity $a) {
            return [
                $a->created_at->format('d/m/Y H:i:s'),
                self::LOG_NAMES[$a->log_name] ?? $a->log_name,
                $a->event ? AuditTrail::eventLabel($a->event) : '-',
                $a->subject_type ? AuditTrail::subjectLabel($a->subject_type) : '-',
                $a->subject_id ?? '-',
                $a->description,
                $a->causer?->name ?? 'Sistem',
                $a->properties?->get('reason') ?? '-',
                $a->attribute_changes?->isNotEmpty() ? json_encode($a->attribute_changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '-',
                $a->ip_address ?? '-',
                $a->url ?? '-',
                $a->batch_uuid ?? '-',
            ];
        });

        $periodSubtitle = $this->isTracing()
            ? 'Penelusuran riwayat (semua tanggal)'
            : 'Periode: '.Carbon::parse($this->from)->translatedFormat('d M Y').' s/d '.Carbon::parse($this->to)->translatedFormat('d M Y');

        return $this->exportFormattedResponse('log-aktivitas', $headers, $rows, 'Laporan Log Aktivitas Sistem', $periodSubtitle, $format);
    }

    public function render()
    {
        return view('livewire.reports.activity-log-report', [
            'activities' => $this->filteredQuery()->paginate($this->perPage),
            'logNames' => self::LOG_NAMES,
            'events' => AuditTrail::EVENTS,
            'subjectLabels' => collect(AuditTrail::SUBJECT_LABELS)->sort()->all(),
        ]);
    }
}
