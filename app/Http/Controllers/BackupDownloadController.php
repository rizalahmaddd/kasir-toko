<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Diunduh lewat route biasa, bukan aksi Livewire, supaya file besar di-stream dan tidak
 * di-encode base64 ke respons JSON.
 */
class BackupDownloadController extends Controller
{
    public function __invoke(string $file, BackupService $backups): StreamedResponse
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        try {
            $path = $backups->path($file);
        } catch (RuntimeException) {
            abort(404);
        }

        activity('settings')->causedBy(auth()->user())
            ->withProperties(['file' => $file])
            ->log("File backup diunduh: {$file}.");

        return Storage::disk('local')->download($path);
    }
}
