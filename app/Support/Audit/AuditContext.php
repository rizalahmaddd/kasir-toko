<?php

namespace App\Support\Audit;

use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Konteks asal sebuah perubahan (IP, perangkat, halaman, batch) yang ditempel ke setiap baris log.
 * Didaftarkan sebagai scoped singleton, jadi satu request/job/perintah artisan = satu batch_uuid:
 * semua perubahan dari satu aksi pengguna bisa ditelusuri bersama.
 */
class AuditContext
{
    private ?string $batchUuid = null;

    public function batchUuid(): string
    {
        return $this->batchUuid ??= (string) Str::uuid();
    }

    /**
     * @return array{batch_uuid: string, ip_address: ?string, user_agent: ?string, http_method: ?string, url: ?string}
     */
    public function toArray(): array
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return [
                'batch_uuid' => $this->batchUuid(),
                'ip_address' => null,
                'user_agent' => null,
                'http_method' => 'CLI',
                'url' => Str::limit(implode(' ', $_SERVER['argv'] ?? ['artisan']), 2000, ''),
            ];
        }

        $request = request();
        $isLivewire = Livewire::isLivewireRequest();

        return [
            'batch_uuid' => $this->batchUuid(),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, '') ?: null,
            'http_method' => $isLivewire ? 'LIVEWIRE' : $request->method(),
            'url' => Str::limit($isLivewire ? (string) Livewire::originalUrl() : $request->fullUrl(), 2000, ''),
        ];
    }
}
