<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Enums\CashMovementType;
use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\Pos\CashMovementRequest;
use App\Http\Requests\Api\V1\Sales\CloseShiftRequest;
use App\Http\Resources\V1\Sales\CashMovementResource;
use App\Http\Resources\V1\Sales\SaleResource;
use App\Http\Resources\V1\Sales\ShiftDetailResource;
use App\Http\Resources\V1\Sales\ShiftResource;
use App\Models\CashShift;
use App\Services\Pos\ShiftService;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

#[ApiTag('Shift Kasir', 'Kasir & Penjualan', 'Rekap shift. Tanpa izin `shifts.manage`, kasir hanya melihat dan menutup shiftnya sendiri.')]
class ShiftController extends Controller
{
    /**
     * Daftar shift.
     */
    #[ApiQuery('status', description: '`variance` = sudah ditutup dan ada selisih uang.', enum: ['open', 'closed', 'variance'])]
    #[ApiQuery('cashier_id', 'integer', 'Hanya untuk akun dengan izin `shifts.manage`.')]
    #[ApiResponse(ShiftResource::class, paginated: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $canManageAll = $user->can('shifts.manage');
        $status = $request->query('status');

        $shifts = CashShift::query()
            ->with(['user', 'closer'])
            ->withCount(['sales as sales_count' => fn (Builder $query) => $query->completed()])
            ->withSum(['sales as sales_total' => fn (Builder $query) => $query->completed()], 'total')
            ->when(! $canManageAll, fn (Builder $query) => $query->where('user_id', $user->id))
            ->when($canManageAll && $request->integer('cashier_id'), fn (Builder $query) => $query->where('user_id', $request->integer('cashier_id')))
            ->when($status === 'open', fn (Builder $query) => $query->whereNull('closed_at'))
            ->when($status === 'closed', fn (Builder $query) => $query->whereNotNull('closed_at'))
            ->when($status === 'variance', fn (Builder $query) => $query->whereNotNull('closed_at')->where('cash_difference', '!=', 0))
            ->latest('opened_at')
            ->paginate($this->perPage($request));

        return ShiftResource::collection($shifts);
    }

    /**
     * Detail & rekap shift.
     */
    public function show(Request $request, CashShift $cashShift): ShiftDetailResource
    {
        $this->authorizeAccess($request, $cashShift);

        return new ShiftDetailResource($cashShift);
    }

    /**
     * Transaksi dalam shift.
     *
     * Maks. 100 terbaru, sama dengan halaman shift di web.
     */
    #[ApiResponse(SaleResource::class, collection: true)]
    public function sales(Request $request, CashShift $cashShift): AnonymousResourceCollection
    {
        $this->authorizeAccess($request, $cashShift);

        return SaleResource::collection(
            $cashShift->sales()->with(['customer', 'cashier', 'payments'])->withCount('items')->latest('sold_at')->limit(100)->get(),
        );
    }

    /**
     * Tutup shift.
     *
     * `counted_cash` = uang fisik di laci. Bila berbeda dengan `summary.expected`, `closing_note`
     * wajib diisi.
     */
    public function close(CloseShiftRequest $request, CashShift $cashShift, ShiftService $shifts): ShiftDetailResource
    {
        $this->authorizeAccess($request, $cashShift);
        $this->ensureOpen($cashShift);

        $countedCash = (int) $request->validated('counted_cash');
        $note = trim((string) $request->validated('closing_note')) ?: null;

        if ($countedCash !== $cashShift->summary()['expected'] && $note === null) {
            throw ValidationException::withMessages(['closing_note' => 'Ada selisih uang. Tulis penjelasannya supaya bisa dicek pemilik.']);
        }

        return new ShiftDetailResource($shifts->close($cashShift, $countedCash, $request->user(), $note));
    }

    /**
     * Catat kas masuk/keluar di shift ini.
     */
    #[ApiResponse(CashMovementResource::class, status: 201)]
    public function recordCash(CashMovementRequest $request, CashShift $cashShift, ShiftService $shifts): CashMovementResource
    {
        $this->authorizeAccess($request, $cashShift);
        $this->ensureOpen($cashShift);
        $data = $request->validated();

        $movement = $shifts->recordCash($cashShift, CashMovementType::from($data['type']), (int) $data['amount'], $data['reason'], $request->user());

        return new CashMovementResource($movement->load('user'));
    }

    private function authorizeAccess(Request $request, CashShift $shift): void
    {
        $user = $request->user();

        abort_unless($shift->user_id === $user->id || $user->can('shifts.manage'), 403);
    }

    private function ensureOpen(CashShift $shift): void
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['message' => 'Shift ini sudah ditutup.']);
        }
    }
}
