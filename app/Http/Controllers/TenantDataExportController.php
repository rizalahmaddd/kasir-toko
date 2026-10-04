<?php

namespace App\Http\Controllers;

use App\Services\TenantDataExporter;
use App\Support\CurrentTenant;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TenantDataExportController extends Controller
{
    public function __invoke(TenantDataExporter $exporter, CurrentTenant $currentTenant): BinaryFileResponse
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);
        abort_if($currentTenant->get() && ! $currentTenant->get()->isPro(), 403, 'Fitur ekspor data toko hanya tersedia untuk paket Pro.');

        $path = $exporter->export();
        $name = 'data-'.($currentTenant->get()?->slug ?? 'toko').'-'.now()->format('Ymd-His').'.zip';

        activity('export')->causedBy(auth()->user())
            ->event('exported')
            ->withProperties(['file' => $name])
            ->log("Mengekspor seluruh data toko ({$name}).");

        return response()->download($path, $name)->deleteFileAfterSend();
    }
}
