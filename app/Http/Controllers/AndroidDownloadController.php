<?php

namespace App\Http\Controllers;

use App\Services\AndroidReleaseStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman unduh APK untuk umum. File dilayani lewat route ini, bukan symlink public/storage,
 * karena APK disimpan di disk privat bersama file rilis yang belum dipublikasikan.
 */
class AndroidDownloadController extends Controller
{
    public function show(AndroidReleaseStore $releases): View
    {
        return view('download.android', ['release' => $releases->latest()]);
    }

    public function download(string $abi, AndroidReleaseStore $releases): StreamedResponse
    {
        $path = $releases->latestPath($abi);

        abort_if($path === null, 404);

        return Storage::disk('local')->download($path, basename($path), [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }
}
