<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\CashMovementType;
use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\Pos\CashMovementRequest;
use App\Http\Requests\Api\V1\Pos\OpenShiftRequest;
use App\Http\Resources\V1\Pos\CurrentShiftResource;
use App\Http\Resources\V1\Sales\CashMovementResource;
use App\Http\Resources\V1\Sales\ShiftDetailResource;
use App\Services\Pos\PosException;
use App\Services\Pos\ShiftService;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Http\Request;

#[ApiTag('Shift Saya', 'Kasir & Penjualan', 'Shift kasir milik akun yang login. Tutup shift lewat `POST /api/v1/shifts/{cashShift}/close`.')]
class CurrentShiftController extends Controller
{
    /**
     * Shift yang sedang dibuka.
     *
     * `shift` null bila belum buka shift.
     */
    public function show(Request $request): CurrentShiftResource
    {
        $shift = $request->user()->openShift();

        return new CurrentShiftResource(['shift' => $shift ? new ShiftDetailResource($shift) : null]);
    }

    /**
     * Buka shift.
     *
     * Bila akun ini sudah punya shift terbuka, shift itu yang dikembalikan.
     */
    #[ApiResponse(ShiftDetailResource::class, status: 201)]
    public function open(OpenShiftRequest $request, ShiftService $shifts): ShiftDetailResource
    {
        return new ShiftDetailResource($shifts->open($request->user(), (int) $request->validated('opening_cash')));
    }

    /**
     * Catat kas masuk/keluar.
     *
     * Dicatat di shift yang sedang dibuka. Kas keluar tidak boleh melebihi uang yang seharusnya
     * ada di laci.
     */
    #[ApiResponse(CashMovementResource::class, status: 201)]
    public function recordCash(CashMovementRequest $request, ShiftService $shifts): CashMovementResource
    {
        $shift = $request->user()->openShift() ?? throw new PosException('Buka shift dulu.', 'no_shift');
        $data = $request->validated();

        $movement = $shifts->recordCash($shift, CashMovementType::from($data['type']), (int) $data['amount'], $data['reason'], $request->user());

        return new CashMovementResource($movement->load('user'));
    }
}
