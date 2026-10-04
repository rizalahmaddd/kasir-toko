<?php

namespace App\Http\Controllers;

use App\Models\CashShift;
use App\Models\Customer;
use App\Models\Sale;
use App\Support\PosSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Dokumen cetak: halaman Blade biasa (bukan Livewire) yang dibuka di tab baru lalu dicetak atau
 * disimpan PDF lewat window.print() bawaan browser, memakai layout x-layouts.print.
 */
class PrintController extends Controller
{
    public function customer(Customer $customer): View
    {
        return view('print.customer', ['customer' => $customer]);
    }

    /**
     * Struk thermal 58/80 mm. ?print=1 langsung membuka dialog cetak, ?embed=1 dipakai iframe
     * cetak otomatis dari layar kasir (tanpa toolbar).
     */
    public function receipt(Request $request, Sale $sale): View
    {
        $user = $request->user();
        abort_unless($sale->user_id === $user->id || $user->can('sales.view'), 403);

        $sale->load('items', 'payments', 'cashier', 'customer');

        return view('print.receipt', [
            'sale' => $sale,
            'width' => in_array($request->query('width'), ['58', '80'], true) ? $request->query('width') : PosSettings::receiptWidth(),
            'autoPrint' => $request->boolean('print') || $request->boolean('embed'),
            'embedded' => $request->boolean('embed'),
        ]);
    }

    public function shift(Request $request, CashShift $cashShift): View
    {
        $user = $request->user();
        abort_unless($cashShift->user_id === $user->id || $user->can('shifts.manage'), 403);

        $cashShift->load('user', 'closer', 'cashMovements.user');

        return view('print.shift', [
            'shift' => $cashShift,
            'summary' => $cashShift->summary(),
            'width' => PosSettings::receiptWidth(),
        ]);
    }
}
