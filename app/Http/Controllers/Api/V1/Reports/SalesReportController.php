<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Resources\V1\Reports\DailySalesResource;
use App\Http\Resources\V1\Reports\ProductSalesResource;
use App\Http\Resources\V1\Reports\SalesSummaryResource;
use App\Livewire\Reports\SalesReport;
use App\Support\DateInput;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The numbers come from the web SalesReport component itself so the app and the web page can
 * never disagree; only the shaping into JSON lives here.
 */
#[ApiTag('Laporan Penjualan', 'Laporan', 'Sama dengan halaman Laporan Penjualan di web. Semua endpoint menerima filter `from`, `to` (default awal bulan s/d hari ini), `cashier_id`, `payment_method`, dan `status`.')]
class SalesReportController extends Controller
{
    /**
     * Ikhtisar penjualan.
     *
     * Omzet, laba kotor, margin, tren, metode pembayaran, produk teratas, kategori, kasir, jam
     * sibuk, dan pelanggan teratas.
     */
    #[ApiQuery('from', 'date')]
    #[ApiQuery('to', 'date')]
    #[ApiQuery('cashier_id', 'integer')]
    #[ApiQuery('payment_method', enum: ['cash', 'qris', 'transfer', 'card'])]
    #[ApiQuery('status', description: '`due` = masih ada kasbon.', enum: ['completed', 'due', 'voided'])]
    #[ApiQuery('chart_metric', description: 'Default `revenue`.', enum: ['revenue', 'profit', 'count'])]
    #[ApiQuery('top_product_metric', description: 'Default `revenue`.', enum: ['revenue', 'profit', 'qty'])]
    public function summary(Request $request): SalesSummaryResource
    {
        $report = $this->report($request);
        $report->setChartMetric((string) $request->query('chart_metric', 'revenue'));
        $report->setTopProductMetric((string) $request->query('top_product_metric', 'revenue'));

        $totals = $this->call($report, 'totals');
        $period = ['from' => $report->from, 'to' => $report->to, 'previous_from' => $totals['prev_from'], 'previous_to' => $totals['prev_to']];
        unset($totals['prev_from'], $totals['prev_to']);

        return new SalesSummaryResource([
            'period' => $period,
            'totals' => $totals,
            'chart' => [
                'metric' => $report->chartMetric,
                'granularity' => Carbon::parse($report->from)->diffInDays(Carbon::parse($report->to)) <= 31 ? 'day' : 'month',
                'points' => $this->call($report, 'chart'),
            ],
            'payments' => $this->call($report, 'paymentsByMethod')->map(fn (array $row) => [
                'method' => $row['method']->value,
                'label' => $row['method']->label(),
                'total' => $row['total'],
                'count' => $row['count'],
            ])->all(),
            'top_products' => $this->call($report, 'topProducts')->map(fn (object $row) => [
                'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                'product_name' => $row->product_name,
                'unit' => $row->unit,
                'category_name' => $row->category_name,
                'qty' => (float) $row->qty,
                'revenue' => (int) $row->revenue,
                'cogs' => (int) round((float) $row->cogs),
                'profit' => (int) round((float) $row->profit),
                'margin' => (float) $row->margin,
            ])->all(),
            'by_category' => $this->call($report, 'byCategory')->map(fn (object $row) => [
                'name' => $row->name,
                'revenue' => (int) $row->revenue,
                'qty' => (float) $row->qty,
                'profit' => (int) round((float) $row->profit),
            ])->all(),
            'by_cashier' => $this->call($report, 'byCashier')->map(fn (object $row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'count' => (int) $row->count,
                'revenue' => (int) $row->revenue,
                'average' => (int) $row->average,
            ])->all(),
            'hourly' => $this->call($report, 'hourlySales')->all(),
            'top_customers' => $this->call($report, 'topCustomers')->map(fn (object $row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'phone' => $row->phone,
                'count' => (int) $row->count,
                'revenue' => (int) $row->revenue,
            ])->all(),
            'customer_segments' => $this->call($report, 'customerSegments'),
        ]);
    }

    /**
     * Rekap harian.
     */
    #[ApiQuery('from', 'date')]
    #[ApiQuery('to', 'date')]
    #[ApiQuery('cashier_id', 'integer')]
    #[ApiQuery('payment_method', enum: ['cash', 'qris', 'transfer', 'card'])]
    #[ApiQuery('status', enum: ['completed', 'due', 'voided'])]
    public function daily(Request $request): DailySalesResource
    {
        $report = $this->report($request);

        return new DailySalesResource([
            'days' => $this->call($report, 'dailyTotals')->map(fn (object $day) => [
                'date' => $day->day,
                'count' => $day->count,
                'qty' => $day->qty,
                'total' => $day->total,
                'discount' => $day->discount,
                'tax' => $day->tax,
                'cogs' => $day->cogs,
                'profit' => $day->profit,
                'margin' => $day->margin,
            ])->all(),
            'summary' => $this->call($report, 'dailySummary'),
        ]);
    }

    /**
     * Analisis produk.
     *
     * Semua produk yang terjual di periode ini.
     */
    #[ApiQuery('from', 'date')]
    #[ApiQuery('to', 'date')]
    #[ApiQuery('cashier_id', 'integer')]
    #[ApiQuery('payment_method', enum: ['cash', 'qris', 'transfer', 'card'])]
    #[ApiQuery('status', enum: ['completed', 'due', 'voided'])]
    #[ApiQuery('search', description: 'Nama produk, SKU, atau kategori.')]
    #[ApiQuery('sort', description: 'Default `revenue`.', enum: ['revenue', 'profit', 'qty', 'margin', 'name'])]
    #[ApiQuery('direction', description: 'Default menurun, kecuali `name`.', enum: ['asc', 'desc'])]
    public function products(Request $request): ProductSalesResource
    {
        $report = $this->report($request);
        $sort = in_array($request->query('sort'), ['revenue', 'profit', 'qty', 'margin', 'name'], true) ? $request->query('sort') : 'revenue';
        $report->productSearch = mb_substr((string) $request->query('search'), 0, 100);
        $report->productSort = $sort;
        $report->productDirection = in_array($request->query('direction'), ['asc', 'desc'], true)
            ? $request->query('direction')
            : ($sort === 'name' ? 'asc' : 'desc');

        return new ProductSalesResource([
            'products' => $this->call($report, 'allProducts')->map(fn (object $row) => [
                'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                'product_name' => $row->product_name,
                'sku' => $row->sku,
                'unit' => $row->unit,
                'category_name' => $row->category_name,
                'qty' => $row->qty,
                'revenue' => $row->revenue,
                'cogs' => $row->cogs,
                'profit' => $row->profit,
                'margin' => (float) $row->margin,
                'avg_price' => $row->avg_price,
            ])->all(),
            'summary' => $this->call($report, 'productsSummary'),
        ]);
    }

    private function report(Request $request): SalesReport
    {
        abort_unless($request->user()->can('reports.sales.view'), 403);

        $from = DateInput::valid((string) $request->query('from')) ?? today()->startOfMonth()->toDateString();
        $to = DateInput::valid((string) $request->query('to')) ?? today()->toDateString();

        /** @var SalesReport $report */
        $report = app('livewire')->new('reports.sales-report');
        [$report->from, $report->to] = $from <= $to ? [$from, $to] : [$to, $from];
        $report->cashierId = $request->integer('cashier_id') ? (string) $request->integer('cashier_id') : '';
        $report->paymentMethod = (string) $request->query('payment_method', '');
        $report->status = in_array($request->query('status'), ['completed', 'due', 'voided'], true) ? $request->query('status') : '';

        return $report;
    }

    private function call(SalesReport $report, string $method): mixed
    {
        return (fn () => $this->{$method}())->call($report);
    }
}
