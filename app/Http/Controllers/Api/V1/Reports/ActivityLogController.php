<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Resources\V1\Reports\ActivityResource;
use App\Livewire\Reports\ActivityLogReport;
use App\Support\DateInput;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\Activitylog\Models\Activity;

#[ApiTag('Log Aktivitas', 'Laporan', 'Jejak audit: perubahan data, login, ekspor, dan pengaturan.')]
class ActivityLogController extends Controller
{
    /**
     * Daftar aktivitas.
     *
     * Terbaru dulu. Dengan `batch`, atau `subject_type` + `subject_id`, rentang tanggal diabaikan
     * supaya riwayat data itu tampil utuh.
     */
    #[ApiQuery('from', 'date', 'Default awal bulan ini.')]
    #[ApiQuery('to', 'date', 'Default hari ini.')]
    #[ApiQuery('log_name', description: 'Kategori log.', enum: ['settings', 'roles', 'audit', 'auth', 'export'])]
    #[ApiQuery('event', description: 'Mis. `created`, `updated`, `deleted`, `voided`.')]
    #[ApiQuery('subject_type', description: 'Nilai `subject_type` dari hasil sebelumnya.')]
    #[ApiQuery('subject_id')]
    #[ApiQuery('causer_id', description: 'ID pengguna, atau `system`.')]
    #[ApiQuery('batch', description: 'UUID batch.')]
    #[ApiQuery('search', description: 'Deskripsi, perubahan, atau IP.')]
    #[ApiResponse(ActivityResource::class, paginated: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('viewAny', Activity::class), 403);

        $from = DateInput::valid((string) $request->query('from')) ?? today()->startOfMonth()->toDateString();
        $to = DateInput::valid((string) $request->query('to')) ?? today()->toDateString();

        /** @var ActivityLogReport $report */
        $report = app('livewire')->new('reports.activity-log-report');
        [$report->from, $report->to] = $from <= $to ? [$from, $to] : [$to, $from];
        $report->logName = (string) $request->query('log_name', '');
        $report->event = (string) $request->query('event', '');
        $report->subjectType = (string) $request->query('subject_type', '');
        $report->subjectId = (string) $request->query('subject_id', '');
        $report->causerId = (string) $request->query('causer_id', '');
        $report->batch = (string) $request->query('batch', '');
        $report->search = mb_substr((string) $request->query('search', ''), 0, 100);
        $report->sortField = 'created_at';
        $report->sortDirection = 'desc';

        /** @var Builder<Activity> $query */
        $query = (fn () => $this->filteredQuery())->call($report);

        return ActivityResource::collection($query->paginate($this->perPage($request)));
    }
}
