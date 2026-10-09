<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Resources\V1\Pos\KitchenTicketResource;
use App\Models\KitchenTicket;
use App\Support\CurrentOutlet;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[ApiTag('Tiket Dapur', 'Kasir & Penjualan', 'Tiket pesanan untuk dapur di outlet aktif. Aktif bila kapabilitas `business.order-type` menyala.')]
class KitchenTicketController extends Controller
{
    /**
     * Daftar tiket dapur.
     *
     * Default tiket yang belum selesai (terlama dulu); `status=done` tiket yang selesai hari ini. Butuh izin `kitchen.view`.
     */
    #[ApiQuery('status', description: '`pending` (default) atau `done`.', enum: ['pending', 'done'])]
    #[ApiResponse(KitchenTicketResource::class, collection: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('kitchen.view'), 403);
        $done = $request->query('status') === KitchenTicket::STATUS_DONE;

        $tickets = KitchenTicket::query()
            ->with('sale', 'user')
            ->where('outlet_id', app(CurrentOutlet::class)->idOrPrimary())
            ->when($done, fn ($query) => $query->where('status', KitchenTicket::STATUS_DONE)->where('done_at', '>=', today())->latest('done_at'))
            ->when(! $done, fn ($query) => $query->where('status', KitchenTicket::STATUS_PENDING)->oldest())
            ->limit(60)
            ->get();

        return KitchenTicketResource::collection($tickets);
    }

    /**
     * Detail tiket dapur (untuk dicetak). Kasir maupun dapur boleh membukanya.
     */
    public function show(Request $request, KitchenTicket $ticket): KitchenTicketResource
    {
        abort_unless($request->user()->can('pos.sell') || $request->user()->can('kitchen.view'), 403);

        return new KitchenTicketResource($ticket->load('sale', 'user'));
    }

    /**
     * Tandai selesai disiapkan.
     */
    public function done(Request $request, KitchenTicket $ticket): KitchenTicketResource
    {
        abort_unless($request->user()->can('kitchen.view'), 403);

        if (! $ticket->isDone()) {
            $ticket->update(['status' => KitchenTicket::STATUS_DONE, 'done_at' => now()]);
        }

        return new KitchenTicketResource($ticket->load('sale', 'user'));
    }

    /**
     * Kembalikan ke antrean.
     */
    public function reopen(Request $request, KitchenTicket $ticket): KitchenTicketResource
    {
        abort_unless($request->user()->can('kitchen.view'), 403);

        $ticket->update(['status' => KitchenTicket::STATUS_PENDING, 'done_at' => null]);

        return new KitchenTicketResource($ticket->load('sale', 'user'));
    }
}
