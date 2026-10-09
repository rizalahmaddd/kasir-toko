<?php

namespace App\Http\Controllers;

use App\Services\Pos\PosException;
use App\Services\Pos\SaleService;
use App\Support\Receipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint JSON (bukan aksi Livewire) supaya layar kasir bisa membedakan penolakan bisnis (422),
 * sesi habis (419), dan koneksi putus, lalu mencoba ulang dengan client_uuid yang sama tanpa
 * risiko transaksi tercatat dua kali.
 */
class PosCheckoutController extends Controller
{
    public function __invoke(Request $request, SaleService $sales): JsonResponse
    {
        try {
            $sale = $sales->checkout($request->user(), $request->all());
        } catch (PosException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
                'reason' => $exception->reason,
                'context' => $exception->context,
            ], 422);
        }

        $sale->load('customer', 'items', 'payments', 'kitchenTickets');

        return response()->json([
            'ok' => true,
            'sale' => [
                'id' => $sale->id,
                'number' => $sale->number,
                'total' => $sale->total,
                'paid' => $sale->paid_amount,
                'change' => $sale->change_amount,
                'due' => $sale->due_amount,
                'receipt_url' => route('pos.receipt', $sale),
                'whatsapp_url' => Receipt::whatsappUrl($sale),
                'order_label' => $sale->orderLabel(),
                'kitchen_ticket_urls' => $sale->kitchenTickets->map(fn ($ticket) => route('print.kitchen-ticket', $ticket))->values()->all(),
            ],
        ]);
    }
}
