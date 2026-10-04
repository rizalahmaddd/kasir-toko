<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CashShift;
use App\Models\Sale;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use App\Support\PosSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

#[ApiTag('Cetak', 'Kasir & Penjualan', 'Dokumen cetak yang sama dengan web dalam bentuk HTML, untuk ditampilkan di WebView lalu dicetak atau disimpan PDF.')]
class PrintController extends Controller
{
    /**
     * Struk thermal (HTML).
     */
    #[ApiQuery('width', description: 'Lebar kertas, default dari Pengaturan Kasir.', enum: ['58', '80'])]
    #[ApiResponse(mediaType: 'text/html', description: 'Halaman HTML struk tanpa toolbar.')]
    public function receipt(Request $request, Sale $sale): Response
    {
        $user = $request->user();
        abort_unless($sale->user_id === $user->id || $user->can('sales.view'), 403);

        $sale->load('items', 'payments', 'cashier', 'customer');

        return response(view('print.receipt', [
            'sale' => $sale,
            'width' => in_array($request->query('width'), ['58', '80'], true) ? $request->query('width') : PosSettings::receiptWidth(),
            'autoPrint' => false,
            'embedded' => true,
        ])->render())->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Rekap shift (HTML).
     */
    #[ApiResponse(mediaType: 'text/html', description: 'Halaman HTML rekap shift.')]
    public function shift(Request $request, CashShift $cashShift): Response
    {
        $user = $request->user();
        abort_unless($cashShift->user_id === $user->id || $user->can('shifts.manage'), 403);

        $cashShift->load('user', 'closer', 'cashMovements.user');

        return response(view('print.shift', [
            'shift' => $cashShift,
            'summary' => $cashShift->summary(),
            'width' => PosSettings::receiptWidth(),
        ])->render())->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
