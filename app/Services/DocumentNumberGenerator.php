<?php

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

/**
 * Nomor dokumen otomatis (mis. INV-2026-0001) yang aman dari race condition
 * lewat row lock di document_sequences, bukan sekadar COUNT/MAX tabel transaksi
 * yang bisa tabrakan kalau dua orang submit bersamaan.
 */
class DocumentNumberGenerator
{
    public function next(string $prefix, int $pad = 4, ?int $year = null): string
    {
        $year ??= now()->year;
        $key = "{$prefix}-{$year}";

        return DB::transaction(function () use ($prefix, $year, $key, $pad) {
            DocumentSequence::query()->firstOrCreate(['key' => $key], ['next_number' => 1]);

            $sequence = DocumentSequence::query()->where('key', $key)->lockForUpdate()->firstOrFail();
            $number = $sequence->next_number;

            $sequence->update(['next_number' => $number + 1]);

            return sprintf('%s-%d-%s', $prefix, $year, str_pad((string) $number, $pad, '0', STR_PAD_LEFT));
        });
    }
}
