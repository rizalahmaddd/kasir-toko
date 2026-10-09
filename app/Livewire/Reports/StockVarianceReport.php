<?php

namespace App\Livewire\Reports;

use App\Enums\StockCountReason;
use App\Enums\StockCountStatus;
use App\Enums\StockMovementType;
use App\Livewire\Concerns\WithDataTable;
use App\Livewire\Concerns\WithDateRangeFilter;
use App\Livewire\Concerns\WithOutletFilter;
use App\Models\Category;
use App\Models\StockCount;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\NumberFormatter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Selisih stok hasil opname per periode: dari dokumen opname yang selesai (setelah koreksi susulan) dan
 * dari opname cepat per produk di halaman Stok, yang tidak punya dokumen.
 */
#[Layout('layouts.app', ['heading' => 'Selisih Stok'])]
#[Title('Laporan Selisih Stok')]
class StockVarianceReport extends Component
{
    use WithDataTable, WithDateRangeFilter, WithOutletFilter;

    public const QUICK = 'quick';

    #[Url]
    public string $categoryId = '';

    #[Url]
    public string $reason = '';

    #[Url]
    public string $counterId = '';

    #[Url]
    public string $direction = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('reports.stock.view'), 403);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['categoryId', 'reason', 'counterId', 'direction', 'from', 'to', 'outletFilter'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Satu baris per barang yang selisih: tanggal, sumber, outlet, produk, kategori, jumlah, nilai, alasan.
     */
    protected function rows(): Builder
    {
        $tenantId = app(CurrentTenant::class)->id();
        $outletId = $this->outletFilterId();
        [$start, $end] = [Carbon::parse($this->from)->startOfDay(), Carbon::parse($this->to)->endOfDay()];

        $documents = DB::table('stock_count_items as i')
            ->join('stock_counts as c', 'c.id', '=', 'i.stock_count_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->leftJoin('categories as k', 'k.id', '=', 'p.category_id')
            ->join('outlets as o', 'o.id', '=', 'c.outlet_id')
            ->where('i.tenant_id', $tenantId)
            ->where('c.tenant_id', $tenantId)
            ->where('c.status', StockCountStatus::Posted->value)
            ->whereBetween('c.posted_at', [$start, $end])
            ->where('i.variance_qty', '!=', 0)
            ->when($outletId, fn (Builder $query) => $query->where('c.outlet_id', $outletId))
            ->when($this->reason !== '' && $this->reason !== self::QUICK, fn (Builder $query) => $query->where('i.reason', $this->reason))
            ->when($this->reason === self::QUICK, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($this->counterId !== '', fn (Builder $query) => $query->whereExists(fn (Builder $entries) => $entries
                ->from('stock_count_entries as e')
                ->whereColumn('e.stock_count_item_id', 'i.id')
                ->whereNull('e.voided_at')
                ->where('e.user_id', (int) $this->counterId)))
            ->selectRaw('c.posted_at as happened_at, c.id as stock_count_id, c.number as source, o.name as outlet_name, p.id as product_id, p.name as product_name, p.sku, p.unit, k.name as category_name, p.category_id, i.variance_qty as quantity, ROUND(i.variance_qty * COALESCE(i.unit_cost, 0)) as value, i.reason as reason, NULL as user_id');

        $quick = DB::table('stock_movements as m')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('categories as k', 'k.id', '=', 'p.category_id')
            ->join('outlets as o', 'o.id', '=', 'm.outlet_id')
            ->where('m.tenant_id', $tenantId)
            ->where('m.type', StockMovementType::Opname->value)
            ->whereNull('m.reference_type')
            ->whereBetween('m.created_at', [$start, $end])
            ->when($outletId, fn (Builder $query) => $query->where('m.outlet_id', $outletId))
            ->when($this->reason !== '' && $this->reason !== self::QUICK, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($this->counterId !== '', fn (Builder $query) => $query->where('m.user_id', (int) $this->counterId))
            ->selectRaw("m.created_at as happened_at, NULL as stock_count_id, 'Opname cepat' as source, o.name as outlet_name, p.id as product_id, p.name as product_name, p.sku, p.unit, k.name as category_name, p.category_id, m.quantity as quantity, ROUND(m.quantity * COALESCE(m.unit_cost, p.cost_price)) as value, NULL as reason, m.user_id as user_id");

        return DB::query()->fromSub($documents->unionAll($quick), 'v')
            ->when($this->categoryId !== '', fn (Builder $query) => $query->where('v.category_id', (int) $this->categoryId))
            ->when($this->direction === 'shortage', fn (Builder $query) => $query->where('v.quantity', '<', 0))
            ->when($this->direction === 'surplus', fn (Builder $query) => $query->where('v.quantity', '>', 0));
    }

    /**
     * @return array{rows: int, shortage_qty: float, shortage_value: int, surplus_qty: float, surplus_value: int, net_value: int}
     */
    protected function totals(): array
    {
        $row = (clone $this->rows())
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) as shortage_qty')
            ->selectRaw('SUM(CASE WHEN value < 0 THEN -value ELSE 0 END) as shortage_value')
            ->selectRaw('SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) as surplus_qty')
            ->selectRaw('SUM(CASE WHEN value > 0 THEN value ELSE 0 END) as surplus_value')
            ->first();

        return [
            'rows' => (int) $row->rows_count,
            'shortage_qty' => round((float) $row->shortage_qty, 3),
            'shortage_value' => (int) $row->shortage_value,
            'surplus_qty' => round((float) $row->surplus_qty, 3),
            'surplus_value' => (int) $row->surplus_value,
            'net_value' => (int) $row->surplus_value - (int) $row->shortage_value,
        ];
    }

    /**
     * @return Collection<int, object{reason_key: string, rows_count: int, value: int}>
     */
    protected function byReason(): Collection
    {
        return (clone $this->rows())
            ->selectRaw("CASE WHEN source = 'Opname cepat' THEN '".self::QUICK."' ELSE COALESCE(reason, '') END as reason_key, COUNT(*) as rows_count, SUM(value) as value")
            ->groupBy('reason_key')
            ->orderByRaw('SUM(value) ASC')
            ->get();
    }

    /**
     * @return Collection<int, object{category_name: ?string, rows_count: int, value: int}>
     */
    protected function byCategory(): Collection
    {
        return (clone $this->rows())
            ->selectRaw('category_name, COUNT(*) as rows_count, SUM(value) as value')
            ->groupBy('category_name')
            ->orderByRaw('SUM(value) ASC')
            ->limit(8)
            ->get();
    }

    public static function reasonLabel(?string $key): string
    {
        return match (true) {
            $key === self::QUICK => 'Opname cepat',
            blank($key) => 'Tanpa alasan',
            default => StockCountReason::tryFrom($key)?->label() ?? $key,
        };
    }

    public function export(string $format = 'xlsx')
    {
        abort_unless((bool) app(CurrentTenant::class)->get()?->isPro(), 403);

        $rows = (clone $this->rows())->orderByDesc('happened_at')->limit(20000)->get()->map(fn (object $row) => [
            Carbon::parse($row->happened_at)->translatedFormat('d M Y H:i'),
            $row->source,
            $row->outlet_name,
            $row->sku,
            $row->product_name,
            $row->category_name ?: '-',
            NumberFormatter::quantity((float) $row->quantity).' '.$row->unit,
            NumberFormatter::currency((int) $row->value),
            $row->source === 'Opname cepat' ? 'Opname cepat' : self::reasonLabel($row->reason),
        ]);

        return $this->exportFormattedResponse('selisih-stok', ['Tanggal', 'Sumber', 'Outlet', 'SKU', 'Barang', 'Kategori', 'Selisih', 'Nilai', 'Alasan'], $rows, 'Laporan Selisih Stok', Carbon::parse($this->from)->translatedFormat('d M Y').' – '.Carbon::parse($this->to)->translatedFormat('d M Y'), $format);
    }

    public function render()
    {
        $tenant = app(CurrentTenant::class)->get();
        $pro = $tenant === null || $tenant->isPro();

        return view('livewire.reports.stock-variance-report', [
            'pro' => $pro,
            'totals' => $pro ? $this->totals() : null,
            'reasons' => $pro ? $this->byReason() : collect(),
            'categoriesBreakdown' => $pro ? $this->byCategory() : collect(),
            'details' => $pro ? (clone $this->rows())->orderByDesc('happened_at')->orderBy('product_name')->paginate($this->perPage) : null,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'counters' => User::query()->whereIn('id', DB::table('stock_count_entries')->where('tenant_id', app(CurrentTenant::class)->id())->select('user_id')->distinct())
                ->orWhereIn('id', DB::table('stock_movements')->where('tenant_id', app(CurrentTenant::class)->id())->where('type', StockMovementType::Opname->value)->select('user_id')->distinct())
                ->orderBy('name')->get(['id', 'name']),
            'outletChoices' => $this->outletFilterChoices(),
            'documentClass' => StockCount::class,
        ]);
    }
}
