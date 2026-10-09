<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AndroidReleaseStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Dipanggil workflow rilis di repo mobile: unggah tiap APK lewat store(), lalu publish() sekali
 * setelah semuanya masuk. Dikunci token statis dari APP_RELEASE_UPLOAD_TOKEN; tanpa token
 * endpoint ini dianggap tidak ada.
 */
class AndroidReleaseUploadController extends Controller
{
    public function store(Request $request, AndroidReleaseStore $releases): JsonResponse
    {
        $this->authorizeUploader($request);

        $validated = $request->validate([
            'version' => ['required', 'string', 'max:50', 'regex:'.AndroidReleaseStore::VERSION_PATTERN],
            'abi' => ['required', Rule::in(AndroidReleaseStore::ABIS)],
            'apk' => ['required', 'file', 'extensions:apk', 'max:204800'],
        ]);

        $path = $releases->store($validated['version'], $validated['abi'], $request->file('apk'));

        return response()->json(['message' => 'APK tersimpan.', 'file' => basename($path)], 201);
    }

    public function publish(Request $request, AndroidReleaseStore $releases): JsonResponse
    {
        $this->authorizeUploader($request);

        $validated = $request->validate([
            'version' => ['required', 'string', 'max:50', 'regex:'.AndroidReleaseStore::VERSION_PATTERN],
            'build' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $release = $releases->publish($validated['version'], $validated['build'] ?? null, $validated['notes'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Rilis dipublikasikan.', 'release' => $release]);
    }

    private function authorizeUploader(Request $request): void
    {
        $token = (string) config('services.app_release.upload_token');

        abort_if($token === '', 404);
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401, 'Token unggah tidak valid.');
    }
}
