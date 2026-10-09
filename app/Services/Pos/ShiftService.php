<?php

namespace App\Services\Pos;

use App\Enums\CashMovementType;
use App\Events\CashShiftClosed;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Outlet;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use App\Support\CurrentOutlet;
use Illuminate\Support\Facades\DB;

class ShiftService
{
    public function __construct(private DocumentNumberGenerator $numbers) {}

    /**
     * Shift dibuka di outlet aktif. Satu user hanya boleh punya satu shift terbuka di seluruh outlet.
     */
    public function open(User $user, int $openingCash, ?int $outletId = null): CashShift
    {
        if ($openingCash < 0) {
            throw new PosException('Modal awal tidak boleh negatif.');
        }

        $outletId ??= app(CurrentOutlet::class)->idOrPrimary() ?? throw new PosException('Toko belum punya outlet.');
        app(CurrentOutlet::class)->ensureOperational($outletId);

        return DB::transaction(function () use ($user, $openingCash, $outletId) {
            // Kunci baris user supaya dua tab/perangkat yang membuka shift bersamaan tidak menghasilkan dua shift.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($existing = $user->openShift()) {
                if ($existing->outlet_id !== $outletId) {
                    $name = Outlet::query()->whereKey($existing->outlet_id)->value('name');

                    throw new PosException("Shift Anda masih terbuka di outlet {$name}. Tutup dulu shift itu sebelum membuka shift di outlet ini.", 'shift_other_outlet');
                }

                return $existing;
            }

            $outlet = Outlet::query()->findOrFail($outletId);

            return CashShift::create([
                'outlet_id' => $outletId,
                'number' => $this->numbers->next('SFT', 5, null, $outlet),
                'user_id' => $user->id,
                'opened_at' => now(),
                'opening_cash' => $openingCash,
            ]);
        });
    }

    public function close(CashShift $shift, int $countedCash, User $closer, ?string $note = null): CashShift
    {
        if ($countedCash < 0) {
            throw new PosException('Uang fisik tidak boleh negatif.');
        }

        return DB::transaction(function () use ($shift, $countedCash, $closer, $note) {
            $locked = CashShift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new PosException('Shift ini sudah ditutup.');
            }

            $expected = $locked->summary()['expected'];

            $diff = $countedCash - $expected;

            $locked->update([
                'closed_at' => now(),
                'closed_by' => $closer->id,
                'expected_cash' => $expected,
                'counted_cash' => $countedCash,
                'cash_difference' => $diff,
                'closing_note' => $note,
            ]);

            CashShiftClosed::dispatch($locked, $closer);

            return $locked;
        });
    }

    public function recordCash(CashShift $shift, CashMovementType $type, int $amount, string $reason, User $user): CashMovement
    {
        if ($amount <= 0) {
            throw new PosException('Nominal harus lebih dari 0.');
        }

        return DB::transaction(function () use ($shift, $type, $amount, $reason, $user) {
            $locked = CashShift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new PosException('Shift sudah ditutup, kas tidak bisa dicatat lagi.');
            }

            if ($type === CashMovementType::Out && $amount > $locked->summary()['expected']) {
                throw new PosException('Kas keluar melebihi uang yang seharusnya ada di laci.');
            }

            return CashMovement::create([
                'outlet_id' => $locked->outlet_id,
                'cash_shift_id' => $locked->id,
                'user_id' => $user->id,
                'type' => $type,
                'amount' => $amount,
                'reason' => $reason,
            ]);
        });
    }
}
