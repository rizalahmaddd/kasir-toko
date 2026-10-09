<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Api\V1\Controller;
use App\Models\DeliveryNote;
use App\Models\Sale;
use App\Services\Pos\DeliveryNoteService;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[ApiTag('Surat Jalan', 'Kasir & Penjualan', 'Surat jalan untuk transaksi yang diantar. Aktif bila kapabilitas `business.delivery-note` menyala.')]
class DeliveryNoteController extends Controller
{
    /**
     * Buat surat jalan.
     */
    public function store(Request $request, Sale $sale, DeliveryNoteService $notes): JsonResponse
    {
        abort_unless($sale->user_id === $request->user()->id || $request->user()->can('sales.view'), 403);

        $note = $notes->create($sale, $request->user(), $request->validate(DeliveryNoteService::rules()));

        return response()->json(['data' => self::payload($note)], 201);
    }

    /**
     * Tandai sudah diterima.
     */
    public function delivered(DeliveryNote $note, DeliveryNoteService $notes): JsonResponse
    {
        return response()->json(['data' => self::payload($notes->markDelivered($note))]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(DeliveryNote $note): array
    {
        return [
            'id' => $note->id,
            'number' => $note->number,
            'recipient' => $note->recipient,
            'phone' => $note->phone,
            'address' => $note->address,
            'project' => $note->project,
            'driver' => $note->driver,
            'vehicle' => $note->vehicle,
            'status' => $note->status,
            'delivered_at' => $note->delivered_at?->toIso8601String(),
            'print_url' => route('delivery-notes.print', $note),
        ];
    }
}
