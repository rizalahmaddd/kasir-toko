<?php

namespace App\Services;

use App\Models\DocumentSequence;
use App\Models\Outlet;
use App\Support\CurrentOutlet;
use Illuminate\Support\Facades\DB;

/**
 * Nomor dokumen otomatis (mis. INV-2026-0001) yang aman dari race condition
 * lewat row lock di document_sequences, bukan sekadar COUNT/MAX tabel transaksi
 * yang bisa tabrakan kalau dua orang submit bersamaan.
 */
class DocumentNumberGenerator
{
    /**
     * Dengan $outlet pada toko multi-outlet, nomor berkode outlet dan urutannya sendiri per outlet
     * (TRX-CB2-2026-000001). Toko satu outlet tetap memakai format lama tanpa kode; kedua urutan
     * tidak bisa bentrok karena kode outlet selalu ada di nomor berkode.
     */
    public function next(string $prefix, int $pad = 4, ?int $year = null, ?Outlet $outlet = null): string
    {
        $year ??= now()->year;
        $outlet = $outlet && app(CurrentOutlet::class)->isMultiOutlet() ? $outlet : null;
        $key = $outlet ? "{$prefix}:{$outlet->id}-{$year}" : "{$prefix}-{$year}";
        $label = $outlet ? "{$prefix}-{$outlet->code}" : $prefix;

        return DB::transaction(function () use ($label, $year, $key, $pad) {
            DocumentSequence::query()->firstOrCreate(['key' => $key], ['next_number' => 1]);

            $sequence = DocumentSequence::query()->where('key', $key)->lockForUpdate()->firstOrFail();
            $number = $sequence->next_number;

            $sequence->update(['next_number' => $number + 1]);

            return sprintf('%s-%d-%s', $label, $year, str_pad((string) $number, $pad, '0', STR_PAD_LEFT));
        });
    }
}
