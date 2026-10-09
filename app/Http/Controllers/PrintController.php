<?php

namespace App\Http\Controllers;

use App\Enums\StockCountStatus;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\DeliveryNote;
use App\Models\KitchenTicket;
use App\Models\Sale;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\OutletIdentity;
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

        $sale->load('items.product', 'items.serials', 'payments', 'cashier', 'customer', 'outlet');

        // Pajak, header, footer, dan QRIS struk mengikuti outlet transaksi, bukan outlet yang sedang dipilih.
        app(CurrentOutlet::class)->set($sale->outlet_id);

        return view('print.receipt', [
            'identity' => OutletIdentity::for($sale->outlet),
            'sale' => $sale,
            'width' => in_array($request->query('width'), ['58', '80'], true) ? $request->query('width') : PosSettings::receiptWidth(),
            'autoPrint' => $request->boolean('print') || $request->boolean('embed'),
            'embedded' => $request->boolean('embed'),
        ]);
    }

    public function kitchenTicket(Request $request, KitchenTicket $ticket): View
    {
        $user = $request->user();
        abort_unless($user->can('pos.sell') || $user->can('kitchen.view'), 403);

        $ticket->load('sale', 'user');
        app(CurrentOutlet::class)->set($ticket->outlet_id);

        return view('print.kitchen-ticket', [
            'ticket' => $ticket,
            'width' => in_array($request->query('width'), ['58', '80'], true) ? $request->query('width') : PosSettings::receiptWidth(),
            'autoPrint' => $request->boolean('print') || $request->boolean('embed'),
            'embedded' => $request->boolean('embed'),
        ]);
    }

    public function deliveryNote(Request $request, DeliveryNote $note): View
    {
        $user = $request->user();
        abort_unless($note->sale->user_id === $user->id || $user->can('sales.view'), 403);

        $note->load('sale.items.serials');

        return view('print.delivery-note', ['note' => $note]);
    }

    public function customerOrder(CustomerOrder $order): View
    {
        $order->load('items', 'payments', 'creator');
        app(CurrentOutlet::class)->set($order->outlet_id);

        return view('print.customer-order', ['order' => $order, 'identity' => OutletIdentity::for($order->outlet)]);
    }

    public function shift(Request $request, CashShift $cashShift): View
    {
        $user = $request->user();
        abort_unless($cashShift->user_id === $user->id || $user->can('shifts.manage'), 403);

        $cashShift->load('user', 'closer', 'cashMovements.user', 'outlet');
        app(CurrentOutlet::class)->set($cashShift->outlet_id);

        return view('print.shift', [
            'identity' => OutletIdentity::for($cashShift->outlet),
            'shift' => $cashShift,
            'summary' => $cashShift->summary(),
            'width' => PosSettings::receiptWidth(),
        ]);
    }

    /**
     * Lembar hitung kosong per kategori. Kolom stok sistem hanya muncul bila opname tidak disembunyikan
     * dari penghitung, atau yang mencetak adalah pengelola opname.
     */
    public function stockCountSheet(Request $request, StockCount $stockCount): View
    {
        abort_unless($request->user()->can('inventory.opname.count'), 403);

        return view('print.stock-count-sheet', [
            'count' => $stockCount->load('outlet'),
            'items' => $stockCount->items()->with(['product.category', 'product.units'])
                ->join('products', 'products.id', '=', 'stock_count_items.product_id')
                ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->orderByRaw('categories.name IS NULL')->orderBy('categories.name')->orderBy('products.name')
                ->select('stock_count_items.*')
                ->get(),
            'showSystem' => ! $stockCount->blind_count || $request->user()->can('inventory.opname.manage'),
            'multiOutlet' => app(CurrentOutlet::class)->isMultiOutlet(),
        ]);
    }

    /**
     * Berita acara opname: ringkasan dan daftar selisih, dengan tanda tangan penghitung dan penyetuju.
     */
    public function stockCountReport(Request $request, StockCount $stockCount): View
    {
        abort_unless($request->user()->can('inventory.opname.count'), 403);
        abort_unless($stockCount->status === StockCountStatus::Posted, 404);

        $items = $stockCount->items()->with('product')->where('variance_qty', '!=', 0)
            ->orderByRaw('ABS(variance_qty * COALESCE(unit_cost, 0)) DESC')
            ->get();

        return view('print.stock-count-report', [
            'count' => $stockCount->load(['outlet', 'creator', 'poster']),
            'items' => $items,
            'counters' => User::query()->whereIn('id', StockCountEntry::query()->whereIn('stock_count_item_id', $stockCount->items()->select('id'))->whereNull('voided_at')->select('user_id'))->orderBy('name')->pluck('name'),
            'firstCountedAt' => StockCountEntry::query()->whereIn('stock_count_item_id', $stockCount->items()->select('id'))->whereNull('voided_at')->min('counted_at'),
            'multiOutlet' => app(CurrentOutlet::class)->isMultiOutlet(),
        ]);
    }
}
