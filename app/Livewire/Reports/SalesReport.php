<?php

namespace App\Livewire\Reports;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Livewire\Concerns\WithDataTable;
use App\Livewire\Concerns\WithDateRangeFilter;
use App\Livewire\Concerns\WithRealtimeRefresh;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Support\NumberFormatter;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app', ['heading' => 'Laporan Penjualan'])]
#[Title('Laporan Penjualan')]
class SalesReport extends Component
{
    use WithDataTable, WithDateRangeFilter, WithRealtimeRefresh;

    /**
     * Tab aktif tampilan laporan:
     * - 'overview': Ikhtisar eksekutif, tren, jam sibuk, distribusi metode & produk
     * - 'daily': Buku rekap penjualan harian
     * - 'transactions': Rincian transaksi per nota dengan audit status
     * - 'products': Analisis performa seluruh produk terjual
     */
    #[Url]
    public string $activeTab = 'overview';

    /**
     * Metrik grafik tren: 'revenue' (omzet), 'profit' (laba kotor), 'count' (jumlah transaksi).
     */
    #[Url]
    public string $chartMetric = 'revenue';

    /**
     * Mode peringkat top produk di ikhtisar: 'qty' (terlaris), 'profit' (paling untung), 'revenue' (omzet terbesar).
     */
    #[Url]
    public string $topProductMetric = 'revenue';

    /**
     * Filter kasir tertentu (user_id).
     */
    #[Url]
    public string $cashierId = '';

    /**
     * Filter metode pembayaran tertentu (cash, qris, transfer, card).
     */
    #[Url]
    public string $paymentMethod = '';

    /**
     * Filter status transaksi ('', 'completed', 'due', 'voided').
     */
    #[Url]
    public string $status = '';

    /**
     * Pencarian pada tab rincian transaksi (no nota, nama pelanggan, nama item).
     */
    #[Url]
    public string $search = '';

    /**
     * Pencarian pada tab analisis produk (nama produk, SKU, kategori).
     */
    #[Url]
    public string $productSearch = '';

    /**
     * Kolom pengurutan tab produk ('revenue', 'profit', 'qty', 'margin', 'name').
     */
    #[Url]
    public string $productSort = 'revenue';

    /**
     * Arah pengurutan tab produk ('desc' atau 'asc').
     */
    #[Url]
    public string $productDirection = 'desc';

    /**
     * ID transaksi yang sedang dibuka detail struknya di modal.
     */
    public ?int $selectedSaleId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('reports.sales.view'), 403);
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['overview', 'daily', 'transactions', 'products'], true) ? $tab : 'overview';
    }

    public function setChartMetric(string $metric): void
    {
        $this->chartMetric = in_array($metric, ['revenue', 'profit', 'count'], true) ? $metric : 'revenue';
    }

    public function setTopProductMetric(string $metric): void
    {
        $this->topProductMetric = in_array($metric, ['qty', 'profit', 'revenue'], true) ? $metric : 'qty';
    }

    public function sortProductsBy(string $field): void
    {
        if ($this->productSort === $field) {
            $this->productDirection = $this->productDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->productSort = $field;
            $this->productDirection = in_array($field, ['name'], true) ? 'asc' : 'desc';
        }
    }

    public function presetToday(): void
    {
        $this->from = $this->to = now()->toDateString();
        $this->resetPage();
    }

    public function presetYesterday(): void
    {
        $this->from = $this->to = now()->subDay()->toDateString();
        $this->resetPage();
    }

    public function presetLast7Days(): void
    {
        $this->from = now()->subDays(6)->toDateString();
        $this->to = now()->toDateString();
        $this->resetPage();
    }

    public function presetLastMonth(): void
    {
        $this->from = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $this->to = now()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->cashierId = '';
        $this->paymentMethod = '';
        $this->status = '';
        $this->search = '';
        $this->productSearch = '';
        $this->resetPage('transactionsPage');
    }

    public function openSaleModal(int $saleId): void
    {
        $this->selectedSaleId = $saleId;
        $this->dispatch('open-modal', 'sale-detail-modal');
    }

    public function closeSaleModal(): void
    {
        $this->selectedSaleId = null;
        $this->dispatch('close-modal', 'sale-detail-modal');
    }

    public function getSelectedSaleProperty(): ?Sale
    {
        if (! $this->selectedSaleId) {
            return null;
        }

        return Sale::with(['cashier', 'customer', 'payments.user', 'items.product', 'shift', 'voider'])->find($this->selectedSaleId);
    }

    /**
     * Query dasar transaksi penjualan dengan filter waktu, kasir, dan metode pembayaran.
     *
     * @return Builder<Sale>
     */
    protected function salesQuery(bool $onlyCompleted = true): Builder
    {
        return Sale::query()
            ->when($onlyCompleted && $this->status !== SaleStatus::Voided->value, fn (Builder $q) => $q->completed())
            ->when($this->status === SaleStatus::Voided->value, fn (Builder $q) => $q->where('status', SaleStatus::Voided->value))
            ->when($this->status === 'due', fn (Builder $q) => $q->completed()->where('due_amount', '>', 0))
            ->when($this->status === 'completed', fn (Builder $q) => $q->where('status', SaleStatus::Completed->value))
            ->whereBetween('sold_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])
            ->when(ctype_digit($this->cashierId), fn (Builder $q) => $q->where('user_id', (int) $this->cashierId))
            ->when(PaymentMethod::tryFrom($this->paymentMethod), fn (Builder $q, PaymentMethod $method) => $q->whereHas('payments', fn (Builder $pq) => $pq->where('method', $method->value)));
    }

    /**
     * Transaksi selesai untuk agregasi finansial (omzet, laba kotor, HPP).
     *
     * @return Builder<Sale>
     */
    protected function sales(): Builder
    {
        return $this->salesQuery(onlyCompleted: true);
    }

    /**
     * Ringkasan eksekutif komprehensif bagi owner:
     * Omzet, laba kotor, HPP, margin %, realisasi kas vs piutang, diskon, pajak, AOV, dan deteksi void.
     *
     * @return array<string, mixed>
     */
    protected function totals(): array
    {
        $sales = $this->sales();
        $row = (clone $sales)->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as revenue, COALESCE(SUM(discount_amount), 0) as discount, COALESCE(SUM(tax_amount), 0) as tax, COALESCE(SUM(due_amount), 0) as due, COALESCE(SUM(paid_amount), 0) as paid')->first();

        $saleIds = (clone $sales)->select('id');
        $items = SaleItem::query()
            ->whereIn('sale_id', $saleIds)
            ->selectRaw('COALESCE(SUM(cost_price * quantity), 0) as cogs, COALESCE(SUM(quantity), 0) as qty')
            ->first();

        $revenue = (int) ($row?->revenue ?? 0);
        $tax = (int) ($row?->tax ?? 0);
        $net = $revenue - $tax;
        $cogs = (int) round((float) ($items?->cogs ?? 0));
        $profit = $net - $cogs;
        $margin = $net > 0 ? round(($profit / $net) * 100, 1) : 0.0;
        $count = (int) ($row?->count ?? 0);
        $discount = (int) ($row?->discount ?? 0);
        $due = (int) ($row?->due ?? 0);
        $paid = (int) ($row?->paid ?? 0);
        $itemsQty = (float) ($items?->qty ?? 0);

        // Jumlah transaksi dengan kasbon
        $dueCount = (clone $sales)->where('due_amount', '>', 0)->count();

        // Transaksi dibatalkan (voided)
        $voidedQuery = Sale::query()
            ->where('status', SaleStatus::Voided->value)
            ->whereBetween('sold_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])
            ->when(ctype_digit($this->cashierId), fn (Builder $q) => $q->where('user_id', (int) $this->cashierId));

        $voidedCount = (clone $voidedQuery)->count();
        $voidedTotal = (int) (clone $voidedQuery)->sum('total');
        $voidRate = ($count + $voidedCount) > 0 ? round(($voidedCount / ($count + $voidedCount)) * 100, 1) : 0.0;

        // Diskon rate (% terhadap subtotal sebelum diskon)
        $subtotalBeforeDiscount = $revenue + $discount;
        $discountRate = $subtotalBeforeDiscount > 0 ? round(($discount / $subtotalBeforeDiscount) * 100, 1) : 0.0;

        // Basket metrics
        $average = $count > 0 ? (int) round($revenue / $count) : 0;
        $itemsPerTransaction = $count > 0 ? round($itemsQty / $count, 1) : 0.0;

        // Komparasi vs Periode Sebelumnya (Previous Period Comparison)
        $from = Carbon::parse($this->from);
        $to = Carbon::parse($this->to);
        $diffDays = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($diffDays - 1);

        $prevSales = Sale::query()
            ->completed()
            ->whereBetween('sold_at', [$prevFrom->toDateString().' 00:00:00', $prevTo->toDateString().' 23:59:59'])
            ->when(ctype_digit($this->cashierId), fn (Builder $q) => $q->where('user_id', (int) $this->cashierId))
            ->when(PaymentMethod::tryFrom($this->paymentMethod), fn (Builder $q, PaymentMethod $method) => $q->whereHas('payments', fn (Builder $pq) => $pq->where('method', $method->value)));

        $prevRow = (clone $prevSales)->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as revenue, COALESCE(SUM(tax_amount), 0) as tax')->first();
        $prevItems = SaleItem::query()
            ->whereIn('sale_id', (clone $prevSales)->select('id'))
            ->selectRaw('COALESCE(SUM(cost_price * quantity), 0) as cogs')
            ->first();

        $prevRevenue = (int) ($prevRow?->revenue ?? 0);
        $prevNet = $prevRevenue - (int) ($prevRow?->tax ?? 0);
        $prevCogs = (int) round((float) ($prevItems?->cogs ?? 0));
        $prevProfit = $prevNet - $prevCogs;
        $prevCount = (int) ($prevRow?->count ?? 0);

        $revenueGrowth = $prevRevenue > 0 ? round((($revenue - $prevRevenue) / $prevRevenue) * 100, 1) : null;
        $profitGrowth = $prevProfit > 0 ? round((($profit - $prevProfit) / $prevProfit) * 100, 1) : null;
        $countGrowth = $prevCount > 0 ? round((($count - $prevCount) / $prevCount) * 100, 1) : null;

        return [
            'revenue' => $revenue,
            'count' => $count,
            'average' => $average,
            'discount' => $discount,
            'discount_rate' => $discountRate,
            'tax' => $tax,
            'cogs' => $cogs,
            'profit' => $profit,
            'margin' => $margin,
            'due' => $due,
            'due_count' => $dueCount,
            'paid' => $paid,
            'voided' => $voidedCount,
            'voided_total' => $voidedTotal,
            'void_rate' => $voidRate,
            'items' => $itemsQty,
            'items_per_transaction' => $itemsPerTransaction,
            'revenue_growth' => $revenueGrowth,
            'profit_growth' => $profitGrowth,
            'count_growth' => $countGrowth,
            'prev_revenue' => $prevRevenue,
            'prev_profit' => $prevProfit,
            'prev_from' => $prevFrom->toDateString(),
            'prev_to' => $prevTo->toDateString(),
        ];
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    protected function chart(): array
    {
        $from = Carbon::parse($this->from);
        $to = Carbon::parse($this->to);
        $daily = $from->diffInDays($to) <= 31;

        $metric = $this->chartMetric;

        $rows = $this->dailyTotals()
            ->groupBy(fn (object $day) => $daily ? $day->day : substr($day->day, 0, 7))
            ->map(fn (Collection $group) => match ($metric) {
                'profit' => (int) $group->sum('profit'),
                'count' => (int) $group->sum('count'),
                default => (int) $group->sum('total'),
            });

        $points = [];
        if ($daily) {
            foreach (CarbonPeriod::create($from, $to) as $day) {
                $points[] = [
                    'label' => $day->translatedFormat($from->diffInDays($to) > 7 ? 'j' : 'D j'),
                    'value' => $rows[$day->format('Y-m-d')] ?? 0,
                ];
            }
        } else {
            foreach (CarbonPeriod::create($from->copy()->startOfMonth(), '1 month', $to) as $month) {
                $points[] = [
                    'label' => $month->translatedFormat('M y'),
                    'value' => $rows[$month->format('Y-m')] ?? 0,
                ];
            }
        }

        return $points;
    }

    /**
     * Rekap per hari terpadu dengan HPP, kuantitas barang, diskon, dan laba kotor.
     *
     * @return Collection<int, object{day: string, count: int, qty: float, total: int, discount: int, tax: int, cogs: int, profit: int, margin: float}>
     */
    protected function dailyTotals(): Collection
    {
        $sales = $this->sales();

        $itemsByDay = SaleItem::query()
            ->whereIn('sale_id', (clone $sales)->select('id'))
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->selectRaw('DATE(sales.sold_at) as day, SUM(sale_items.quantity) as qty, SUM(sale_items.cost_price * sale_items.quantity) as cogs')
            ->groupByRaw('DATE(sales.sold_at)')
            ->get()
            ->keyBy('day');

        return (clone $sales)->toBase()
            ->selectRaw('DATE(sold_at) as day, COUNT(*) as count, SUM(total) as total, SUM(discount_amount) as discount, SUM(tax_amount) as tax')
            ->groupByRaw('DATE(sold_at)')
            ->orderBy('day')
            ->get()
            ->map(function (object $row) use ($itemsByDay) {
                $dayItems = $itemsByDay->get($row->day);
                $cogs = (int) round((float) ($dayItems?->cogs ?? 0));
                $qty = (float) ($dayItems?->qty ?? 0);
                $net = (int) $row->total - (int) $row->tax;
                $profit = $net - $cogs;
                $margin = $net > 0 ? round(($profit / $net) * 100, 1) : 0.0;

                return (object) [
                    'day' => (string) $row->day,
                    'count' => (int) $row->count,
                    'qty' => $qty,
                    'total' => (int) $row->total,
                    'discount' => (int) $row->discount,
                    'tax' => (int) $row->tax,
                    'cogs' => $cogs,
                    'profit' => $profit,
                    'margin' => $margin,
                ];
            });
    }

    /**
     * Total ringkasan untuk baris footer rekap harian.
     *
     * @return array{count: int, qty: float, discount: int, tax: int, cogs: int, profit: int, margin: float, total: int}
     */
    protected function dailySummary(): array
    {
        $rows = $this->dailyTotals();
        $totalRevenue = (int) $rows->sum('total');
        $totalTax = (int) $rows->sum('tax');
        $net = $totalRevenue - $totalTax;
        $totalCogs = (int) $rows->sum('cogs');
        $totalProfit = $net - $totalCogs;
        $margin = $net > 0 ? round(($totalProfit / $net) * 100, 1) : 0.0;

        return [
            'count' => (int) $rows->sum('count'),
            'qty' => (float) $rows->sum('qty'),
            'discount' => (int) $rows->sum('discount'),
            'tax' => $totalTax,
            'cogs' => $totalCogs,
            'profit' => $totalProfit,
            'margin' => $margin,
            'total' => $totalRevenue,
        ];
    }

    /**
     * Pola penjualan per jam untuk analisa jam sibuk (Peak Hours).
     *
     * @return Collection<int, array{hour: string, label: string, count: int, revenue: int, is_peak: bool}>
     */
    protected function hourlySales(): Collection
    {
        $driver = DB::connection()->getDriverName();
        $hourExpr = match ($driver) {
            'sqlite' => "strftime('%H', sold_at)",
            'pgsql' => "to_char(sold_at, 'HH24')",
            default => "DATE_FORMAT(sold_at, '%H')",
        };

        $rows = $this->sales()
            ->selectRaw("{$hourExpr} as hour, COUNT(*) as count, SUM(total) as revenue")
            ->groupByRaw($hourExpr)
            ->get()
            ->keyBy(fn ($item) => str_pad((string) $item->hour, 2, '0', STR_PAD_LEFT));

        $maxCount = $rows->max('count') ?: 1;

        $result = collect();
        for ($h = 7; $h <= 22; $h++) {
            $key = str_pad((string) $h, 2, '0', STR_PAD_LEFT);
            $row = $rows->get($key);
            $count = (int) ($row?->count ?? 0);
            $revenue = (int) ($row?->revenue ?? 0);

            $result->push([
                'hour' => $key,
                'label' => "{$key}:00",
                'count' => $count,
                'revenue' => $revenue,
                'is_peak' => $count > 0 && $count === $maxCount,
            ]);
        }

        return $result;
    }

    /**
     * Pembayaran yang diterima di periode ini.
     *
     * @return Collection<int, array{method: PaymentMethod, total: int, count: int}>
     */
    protected function paymentsByMethod(): Collection
    {
        return SalePayment::query()
            ->whereHas('sale', fn (Builder $query) => $query->completed())
            ->whereBetween('paid_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('method')
            ->get()
            ->map(fn (SalePayment $row) => ['method' => $row->method, 'total' => (int) $row->getAttribute('total'), 'count' => (int) $row->getAttribute('count')])
            ->sortByDesc('total')
            ->values();
    }

    /**
     * Top produk dengan mode fleksibel: kuantitas (qty), laba kotor (profit), atau omzet (revenue).
     *
     * @return Collection<int, object>
     */
    protected function topProducts(): Collection
    {
        $orderColumn = match ($this->topProductMetric) {
            'profit' => 'profit',
            'qty' => 'qty',
            default => 'revenue',
        };

        return SaleItem::query()
            ->whereIn('sale_id', $this->sales()->select('id'))
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->selectRaw("sale_items.product_id, sale_items.product_name, sale_items.unit, COALESCE(categories.name, 'Tanpa kategori') as category_name, SUM(sale_items.quantity) as qty, SUM(sale_items.total) as revenue, (SUM(sale_items.total) - SUM(sale_items.cost_price * sale_items.quantity)) as profit, SUM(sale_items.cost_price * sale_items.quantity) as cogs")
            ->groupBy('sale_items.product_id', 'sale_items.product_name', 'sale_items.unit', 'category_name')
            ->orderByDesc($orderColumn)
            ->limit(10)
            ->toBase()
            ->get()
            ->map(function (object $row) {
                $revenue = (int) $row->revenue;
                $profit = (int) $row->profit;
                $row->margin = $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0.0;

                return $row;
            });
    }

    /**
     * @return Collection<int, object>
     */
    protected function byCategory(): Collection
    {
        return SaleItem::query()
            ->whereIn('sale_items.sale_id', $this->sales()->select('id'))
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->selectRaw("COALESCE(categories.name, 'Tanpa kategori') as name, SUM(sale_items.total) as revenue, SUM(sale_items.quantity) as qty, (SUM(sale_items.total) - SUM(sale_items.cost_price * sale_items.quantity)) as profit")
            ->groupBy(DB::raw("COALESCE(categories.name, 'Tanpa kategori')"))
            ->orderByDesc('revenue')
            ->toBase()
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    protected function byCashier(): Collection
    {
        return $this->sales()
            ->join('users', 'users.id', '=', 'sales.user_id')
            ->selectRaw('users.id, users.name as name, COUNT(*) as count, SUM(sales.total) as revenue')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('revenue')
            ->toBase()
            ->get()
            ->map(function (object $row) {
                $row->average = $row->count > 0 ? (int) round($row->revenue / $row->count) : 0;

                return $row;
            });
    }

    /**
     * 5 Pelanggan dengan akumulasi belanja terbesar di periode ini.
     *
     * @return Collection<int, object>
     */
    protected function topCustomers(): Collection
    {
        return $this->sales()
            ->whereNotNull('customer_id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->selectRaw('customers.id, customers.name, customers.phone, COUNT(*) as count, SUM(sales.total) as revenue')
            ->groupBy('customers.id', 'customers.name', 'customers.phone')
            ->orderByDesc('revenue')
            ->limit(5)
            ->toBase()
            ->get();
    }

    /**
     * Kontribusi Pelanggan Terdaftar (Member) vs Pelanggan Umum.
     *
     * @return array{member_revenue: int, member_count: int, member_percent: float, general_revenue: int, general_count: int, general_percent: float}
     */
    protected function customerSegments(): array
    {
        $sales = $this->sales();
        $memberRow = (clone $sales)->whereNotNull('customer_id')->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as revenue')->first();
        $generalRow = (clone $sales)->whereNull('customer_id')->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as revenue')->first();

        $memberRev = (int) ($memberRow?->revenue ?? 0);
        $generalRev = (int) ($generalRow?->revenue ?? 0);
        $totalRev = max(1, $memberRev + $generalRev);

        return [
            'member_revenue' => $memberRev,
            'member_count' => (int) ($memberRow?->count ?? 0),
            'member_percent' => round(($memberRev / $totalRev) * 100, 1),
            'general_revenue' => $generalRev,
            'general_count' => (int) ($generalRow?->count ?? 0),
            'general_percent' => round(($generalRev / $totalRev) * 100, 1),
        ];
    }

    /**
     * Daftar transaksi terpaginasi untuk Tab Rincian Transaksi.
     */
    protected function transactions(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return Sale::query()
            ->with(['cashier', 'customer', 'payments', 'items'])
            ->whereBetween('sold_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])
            ->when(ctype_digit($this->cashierId), fn (Builder $q) => $q->where('user_id', (int) $this->cashierId))
            ->when(PaymentMethod::tryFrom($this->paymentMethod), fn (Builder $q, PaymentMethod $method) => $q->whereHas('payments', fn (Builder $pq) => $pq->where('method', $method->value)))
            ->when($this->status === SaleStatus::Voided->value, fn (Builder $q) => $q->where('status', SaleStatus::Voided->value))
            ->when($this->status === 'completed', fn (Builder $q) => $q->where('status', SaleStatus::Completed->value))
            ->when($this->status === 'due', fn (Builder $q) => $q->completed()->where('due_amount', '>', 0))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $sub) => $sub
                ->where('number', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $cq) => $cq->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))
                ->orWhereHas('items', fn (Builder $iq) => $iq->where('product_name', 'like', "%{$term}%"))
            ))
            ->orderBy('sold_at', 'desc')
            ->paginate($this->perPage, ['*'], 'transactionsPage');
    }

    /**
     * Analisis seluruh produk terjual dengan pencarian dan pengurutan.
     *
     * @return Collection<int, object>
     */
    protected function allProducts(): Collection
    {
        $term = trim($this->productSearch);

        $query = SaleItem::query()
            ->whereIn('sale_items.sale_id', $this->sales()->select('id'))
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->selectRaw("sale_items.product_id, sale_items.product_name, sale_items.sku, sale_items.unit, COALESCE(categories.name, 'Tanpa kategori') as category_name, SUM(sale_items.quantity) as qty, SUM(sale_items.total) as revenue, SUM(sale_items.cost_price * sale_items.quantity) as cogs, (SUM(sale_items.total) - SUM(sale_items.cost_price * sale_items.quantity)) as profit")
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $sub) => $sub
                ->where('sale_items.product_name', 'like', "%{$term}%")
                ->orWhere('sale_items.sku', 'like', "%{$term}%")
                ->orWhere('categories.name', 'like', "%{$term}%")
            ))
            ->groupBy('sale_items.product_id', 'sale_items.product_name', 'sale_items.sku', 'sale_items.unit', 'category_name');

        $rows = $query->toBase()->get()->map(function (object $row) {
            $revenue = (int) $row->revenue;
            $profit = (int) $row->profit;
            $row->cogs = (int) $row->cogs;
            $row->revenue = $revenue;
            $row->profit = $profit;
            $row->qty = (float) $row->qty;
            $row->margin = $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0.0;
            $row->avg_price = $row->qty > 0 ? (int) round($revenue / $row->qty) : 0;

            return $row;
        });

        $sortField = $this->productSort;
        $isDesc = $this->productDirection === 'desc';

        return $rows->sortBy(function (object $row) use ($sortField) {
            return match ($sortField) {
                'qty' => $row->qty,
                'profit' => $row->profit,
                'margin' => $row->margin,
                'name' => strtolower($row->product_name),
                default => $row->revenue,
            };
        }, SORT_REGULAR, $isDesc)->values();
    }

    /**
     * Total ringkasan analisis seluruh produk.
     *
     * @return array{qty: float, revenue: int, cogs: int, profit: int, margin: float}
     */
    protected function productsSummary(): array
    {
        $rows = $this->allProducts();
        $totalRevenue = (int) $rows->sum('revenue');
        $totalCogs = (int) $rows->sum('cogs');
        $totalProfit = $totalRevenue - $totalCogs;
        $margin = $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 1) : 0.0;

        return [
            'qty' => (float) $rows->sum('qty'),
            'revenue' => $totalRevenue,
            'cogs' => $totalCogs,
            'profit' => $totalProfit,
            'margin' => $margin,
        ];
    }

    /**
     * Ekspor fleksibel mengikuti tab aktif atau parameter format.
     */
    public function export(string $format = 'xlsx', ?string $type = null)
    {
        $type = $type ?? match ($this->activeTab) {
            'transactions' => 'transactions',
            'products' => 'products',
            default => 'daily',
        };

        return match ($type) {
            'transactions' => $this->exportTransactions($format),
            'products' => $this->exportProducts($format),
            default => $this->exportDaily($format),
        };
    }

    public function exportDaily(string $format = 'xlsx'): StreamedResponse
    {
        $rows = $this->dailyTotals()->map(fn (object $day) => [
            Carbon::parse($day->day)->translatedFormat('d M Y'),
            Carbon::parse($day->day)->translatedFormat('l'),
            (int) $day->count,
            (float) $day->qty,
            NumberFormatter::currency((int) $day->discount),
            NumberFormatter::currency((int) $day->tax),
            NumberFormatter::currency((int) $day->cogs),
            NumberFormatter::currency((int) $day->profit),
            $day->margin.'%',
            NumberFormatter::currency((int) $day->total),
        ]);

        return $this->exportFormattedResponse(
            'laporan-penjualan-harian',
            ['Tanggal', 'Hari', 'Transaksi', 'Barang Terjual', 'Diskon', 'Pajak', 'HPP Modal', 'Laba Kotor', 'Margin', 'Omzet'],
            $rows,
            'Laporan Penjualan Harian',
            "{$this->from} s/d {$this->to}",
            $format
        );
    }

    public function exportTransactions(string $format = 'xlsx'): StreamedResponse
    {
        $term = trim($this->search);
        $sales = Sale::query()
            ->with(['cashier', 'customer', 'payments', 'items'])
            ->whereBetween('sold_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])
            ->when(ctype_digit($this->cashierId), fn (Builder $q) => $q->where('user_id', (int) $this->cashierId))
            ->when(PaymentMethod::tryFrom($this->paymentMethod), fn (Builder $q, PaymentMethod $method) => $q->whereHas('payments', fn (Builder $pq) => $pq->where('method', $method->value)))
            ->when($this->status === SaleStatus::Voided->value, fn (Builder $q) => $q->where('status', SaleStatus::Voided->value))
            ->when($this->status === 'completed', fn (Builder $q) => $q->where('status', SaleStatus::Completed->value))
            ->when($this->status === 'due', fn (Builder $q) => $q->completed()->where('due_amount', '>', 0))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $sub) => $sub
                ->where('number', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $cq) => $cq->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))
                ->orWhereHas('items', fn (Builder $iq) => $iq->where('product_name', 'like', "%{$term}%"))
            ))
            ->orderBy('sold_at', 'desc')
            ->get();

        $rows = $sales->map(function (Sale $sale) {
            $cogs = (int) $sale->items->sum(fn ($i) => (float) $i->cost_price * (float) $i->quantity);
            $net = $sale->total - $sale->tax_amount;
            $profit = $net - $cogs;
            $methods = $sale->payments->pluck('method')->map(fn ($m) => $m->label())->unique()->implode(', ');

            return [
                $sale->number,
                $sale->sold_at->translatedFormat('d/m/Y H:i'),
                $sale->cashier?->name ?? '-',
                $sale->customer?->name ?? 'Umum',
                $methods ?: '-',
                NumberFormatter::currency($sale->subtotal),
                NumberFormatter::currency($sale->discount_amount),
                NumberFormatter::currency($sale->tax_amount),
                NumberFormatter::currency($sale->total),
                NumberFormatter::currency($cogs),
                NumberFormatter::currency($profit),
                $sale->status->label(),
            ];
        });

        return $this->exportFormattedResponse(
            'rincian-transaksi-penjualan',
            ['No. Nota', 'Waktu', 'Kasir', 'Pelanggan', 'Metode Bayar', 'Subtotal', 'Diskon', 'Pajak', 'Total', 'HPP', 'Laba Kotor', 'Status'],
            $rows,
            'Rincian Transaksi Penjualan',
            "{$this->from} s/d {$this->to}",
            $format
        );
    }

    public function exportProducts(string $format = 'xlsx'): StreamedResponse
    {
        $products = $this->allProducts();
        $totalRevenue = max(1, (int) $products->sum('revenue'));

        $rows = $products->map(function (object $row) use ($totalRevenue) {
            $share = round(($row->revenue / $totalRevenue) * 100, 1);

            return [
                $row->sku ?: '-',
                $row->product_name,
                $row->category_name,
                (float) $row->qty.' '.$row->unit,
                NumberFormatter::currency($row->avg_price),
                NumberFormatter::currency((int) $row->revenue),
                NumberFormatter::currency((int) $row->cogs),
                NumberFormatter::currency((int) $row->profit),
                $row->margin.'%',
                $share.'%',
            ];
        });

        return $this->exportFormattedResponse(
            'analisis-produk-terjual',
            ['SKU', 'Nama Produk', 'Kategori', 'Terjual', 'Harga Rata-rata', 'Omzet', 'HPP Modal', 'Laba Kotor', 'Margin', 'Kontribusi Omzet'],
            $rows,
            'Analisis Produk Terjual',
            "{$this->from} s/d {$this->to}",
            $format
        );
    }

    public function render()
    {
        $totals = $this->totals();
        $byCategory = $this->byCategory();

        return view('livewire.reports.sales-report', [
            'totals' => $totals,
            'chart' => $this->chart(),
            'chartDaily' => Carbon::parse($this->from)->diffInDays(Carbon::parse($this->to)) <= 31,
            'payments' => $this->paymentsByMethod(),
            'topProducts' => $this->topProducts(),
            'byCategory' => $byCategory,
            'categoryTotal' => max(1, (int) $byCategory->sum('revenue')),
            'byCashier' => $this->byCashier(),
            'hourlySales' => $this->hourlySales(),
            'peakHour' => $this->hourlySales()->firstWhere('is_peak', true),
            'topCustomers' => $this->topCustomers(),
            'customerSegments' => $this->customerSegments(),
            'dailyTotals' => $this->dailyTotals(),
            'dailySummary' => $this->dailySummary(),
            'transactions' => $this->activeTab === 'transactions' ? $this->transactions() : null,
            'products' => $this->activeTab === 'products' ? $this->allProducts() : null,
            'productsSummary' => $this->activeTab === 'products' ? $this->productsSummary() : null,
            'cashiers' => User::query()->orderBy('name')->get(['id', 'name']),
            'selectedSale' => $this->selectedSale,
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function realtimeEvents(): array
    {
        return ['sale.recorded'];
    }
}
