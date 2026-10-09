<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * APK rilis Android yang diunggah CI. File masuk ke folder versinya dulu dan baru tampil di halaman
 * unduh setelah publish(), jadi pengunjung tidak pernah melihat rilis yang baru setengah terunggah.
 * Hanya rilis terakhir yang disimpan; folder versi lama dihapus saat publish.
 */
class AndroidReleaseStore
{
    public const DIRECTORY = 'app-releases/android';

    public const VERSION_PATTERN = '/^\d+\.\d+\.\d+([.+-][0-9A-Za-z.+-]+)?$/';

    /** Urutan ini juga urutan tampil di halaman unduh; yang pertama jadi tombol utama. */
    public const ABIS = ['arm64-v8a', 'armeabi-v7a', 'x86_64', 'universal'];

    private const MANIFEST = self::DIRECTORY.'/latest.json';

    private const FILE_PREFIX = 'kasirtoko';

    public function store(string $version, string $abi, UploadedFile $apk): string
    {
        $this->assertValid($version, $abi);

        return Storage::disk('local')->putFileAs($this->versionDirectory($version), $apk, $this->fileName($version, $abi));
    }

    /**
     * @return array{version: string, build: ?int, notes: ?string, published_at: string, files: list<array{abi: string, name: string, size: int, sha256: string}>}
     */
    public function publish(string $version, ?int $build = null, ?string $notes = null): array
    {
        $this->assertValid($version);

        $disk = Storage::disk('local');
        $files = [];

        foreach (self::ABIS as $abi) {
            $path = $this->versionDirectory($version).'/'.$this->fileName($version, $abi);

            if ($disk->exists($path)) {
                $files[] = [
                    'abi' => $abi,
                    'name' => basename($path),
                    'size' => $disk->size($path),
                    'sha256' => hash_file('sha256', $disk->path($path)),
                ];
            }
        }

        if ($files === []) {
            throw new RuntimeException("Belum ada APK yang diunggah untuk versi {$version}.");
        }

        $manifest = [
            'version' => $version,
            'build' => $build,
            'notes' => $notes,
            'published_at' => now()->toIso8601String(),
            'files' => $files,
        ];

        $disk->put(self::MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        foreach ($disk->directories(self::DIRECTORY) as $directory) {
            if ($directory !== $this->versionDirectory($version)) {
                $disk->deleteDirectory($directory);
            }
        }

        return $manifest;
    }

    /**
     * @return array{version: string, build: ?int, notes: ?string, published_at: string, files: list<array{abi: string, name: string, size: int, sha256: string}>}|null
     */
    public function latest(): ?array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::MANIFEST)) {
            return null;
        }

        $manifest = json_decode((string) $disk->get(self::MANIFEST), true);

        return is_array($manifest) && ($manifest['files'] ?? []) !== [] ? $manifest : null;
    }

    /**
     * Path file APK rilis terbaru untuk ABI tersebut, atau null kalau ABI itu tidak ikut dirilis.
     */
    public function latestPath(string $abi): ?string
    {
        $release = $this->latest();
        $file = collect($release['files'] ?? [])->firstWhere('abi', $abi);

        if ($file === null) {
            return null;
        }

        $path = $this->versionDirectory($release['version']).'/'.$file['name'];

        return Storage::disk('local')->exists($path) ? $path : null;
    }

    private function versionDirectory(string $version): string
    {
        return self::DIRECTORY.'/'.$version;
    }

    private function fileName(string $version, string $abi): string
    {
        return self::FILE_PREFIX."-{$version}-{$abi}.apk";
    }

    private function assertValid(string $version, ?string $abi = null): void
    {
        if (! preg_match(self::VERSION_PATTERN, $version)) {
            throw new RuntimeException("Format versi tidak valid: {$version}.");
        }

        if ($abi !== null && ! in_array($abi, self::ABIS, true)) {
            throw new RuntimeException("ABI tidak dikenal: {$abi}.");
        }
    }
}
