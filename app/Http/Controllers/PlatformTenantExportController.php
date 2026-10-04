<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\TenantDataExporter;
use App\Support\CurrentTenant;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin platform mengunduh seluruh data satu toko, mis. untuk diserahkan ke pemilik yang
 * berhenti berlangganan dan tidak bisa masuk lagi.
 */
class PlatformTenantExportController extends Controller
{
    public function __invoke(Tenant $tenant, TenantDataExporter $exporter, CurrentTenant $currentTenant): BinaryFileResponse
    {
        abort_unless(auth()->user()->can('manage-platform'), 403);

        $path = $currentTenant->run($tenant, fn () => $exporter->export());
        $name = "data-{$tenant->slug}-".now()->format('Ymd-His').'.zip';

        activity('platform')->causedBy(auth()->user())
            ->performedOn($tenant)
            ->event('exported')
            ->withProperties(['file' => $name])
            ->log("Mengekspor seluruh data toko {$tenant->name} ({$name}).");

        return response()->download($path, $name)->deleteFileAfterSend();
    }
}
