<?php

namespace App\Services\Pos;

use App\Enums\CashMovementType;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;

class ShiftService
{
    public function __construct(private DocumentNumberGenerator $numbers) {}

    public function open(User $user, int $openingCash): CashShift
    {
        if ($openingCash < 0) {
            throw new PosException('Modal awal tidak boleh negatif.');
        }

        return DB::transaction(function () use ($user, $openingCash) {
            // Kunci baris user supaya dua tab/perangkat yang membuka shift bersamaan tidak menghasilkan dua shift.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($existing = $user->openShift()) {
                return $existing;
            }

            return CashShift::create([
                'number' => $this->numbers->next('SFT', 5),
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

            $locked->update([
                'closed_at' => now(),
                'closed_by' => $closer->id,
                'expected_cash' => $expected,
                'counted_cash' => $countedCash,
                'cash_difference' => $countedCash - $expected,
                'closing_note' => $note,
            ]);

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
                'cash_shift_id' => $locked->id,
                'user_id' => $user->id,
                'type' => $type,
                'amount' => $amount,
                'reason' => $reason,
            ]);
        });
    }
}
