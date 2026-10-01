<?php

namespace App\Livewire;

use App\Livewire\Concerns\WithRealtimeRefresh;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Support\NumberFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

#[Layout('layouts.app', ['heading' => 'Dashboard'])]
#[Title('Dashboard')]
class Dashboard extends Component
{
    use WithRealtimeRefresh;

    private function seesAllSales(): bool
    {
        $user = Auth::user();

        return $user->can('sales.view') || $user->can('reports.sales.view');
    }

    /**
     * Penjualan yang boleh dilihat user ini: semua kasir untuk pemilik/admin, milik sendiri untuk kasir.
     *
     * @return Builder<Sale>|null
     */
    private function visibleSales(): ?Builder
    {
        $user = Auth::user();

        if ($this->seesAllSales()) {
            return Sale::query()->completed();
        }

        return $user->can('pos.sell') ? Sale::query()->completed()->where('user_id', $user->id) : null;
    }

    /**
     * @return array{revenue: int, count: int, yesterday: int, profit: ?int}|null
     */
    public function today(): ?array
    {
        $sales = $this->visibleSales();

        if (! $sales) {
            return null;
        }

        $today = (clone $sales)->whereDate('sold_at', today());
        $revenue = (int) (clone $today)->sum('total');
        $profit = null;

        if (Auth::user()->can('reports.sales.view')) {
            $cogs = (float) SaleItem::query()->whereIn('sale_id', (clone $today)->select('id'))->selectRaw('COALESCE(SUM(cost_price * quantity), 0) as cogs')->value('cogs');
            $profit = $revenue - (int) (clone $today)->sum('tax_amount') - (int) round($cogs);
        }

        return [
            'revenue' => $revenue,
            'count' => (clone $today)->count(),
            'yesterday' => (int) (clone $sales)->whereDate('sold_at', today()->subDay())->sum('total'),
            'profit' => $profit,
        ];
    }

    /**
     * @return array<int, array{title: string, value: string, icon: string, tone: string, subtitle: ?string, href: ?string}>
     */
    public function stats(?array $today): array
    {
        $user = Auth::user();
        $stats = [];

        if ($today !== null) {
            $stats[] = [
                'title' => $this->seesAllSales() ? 'Transaksi hari ini' : 'Transaksi saya hari ini',
                'value' => NumberFormatter::quantity($today['count']),
                'icon' => 'receipt',
                'tone' => 'slate',
                'subtitle' => $today['count'] > 0 ? 'Rata-rata '.NumberFormatter::currency(intdiv($today['revenue'], $today['count'])) : null,
                'href' => route('sales.index'),
            ];
        }

        if ($today !== null && $today['profit'] !== null) {
            $stats[] = [
                'title' => 'Laba kotor hari ini',
                'value' => NumberFormatter::currency($today['profit']),
                'icon' => 'trending-up',
                'tone' => $today['profit'] < 0 ? 'rose' : 'emerald',
                'subtitle' => 'Penjualan dikurangi HPP',
                'href' => route('reports.sales', ['from' => today()->toDateString(), 'to' => today()->toDateString()]),
            ];
        }

        if ($user->can('view-master-data')) {
            $low = Product::query()->where('is_active', true)->lowStock()->count();
            $stats[] = [
                'title' => 'Stok menipis / habis',
                'value' => NumberFormatter::quantity($low),
                'icon' => 'package-x',
                'tone' => $low > 0 ? 'amber' : 'slate',
                'subtitle' => $low > 0 ? 'Perlu belanja ulang' : 'Semua stok aman',
                'href' => route('inventory.stock', ['level' => 'low']),
            ];
        }

        if ($user->can('receivables.manage')) {
            $due = (int) Sale::query()->completed()->sum('due_amount');
            $stats[] = [
                'title' => 'Kasbon belum lunas',
                'value' => NumberFormatter::currency($due),
                'icon' => 'hand-coins',
                'tone' => $due > 0 ? 'amber' : 'slate',
                'subtitle' => null,
                'href' => route('receivables.index'),
            ];
        }

        if ($user->can('view-master-data')) {
            $stats[] = [
                'title' => 'Pelanggan',
                'value' => NumberFormatter::quantity(Customer::count()),
                'icon' => 'users',
                'tone' => 'slate',
                'subtitle' => NumberFormatter::quantity(Customer::where('is_active', true)->count()).' aktif',
                'href' => route('master-data.customers'),
            ];
        }

        if ($user->isSuperAdmin()) {
            $stats[] = [
                'title' => 'Pengguna',
                'value' => NumberFormatter::quantity(User::count()),
                'icon' => 'user-cog',
                'tone' => 'slate',
                'subtitle' => null,
                'href' => route('settings.roles-and-permissions'),
            ];
        }

        return $stats;
    }

    /**
     * @return list<array{label: string, value: int}>|null
     */
    public function weekChart(): ?array
    {
        if (! Auth::user()->can('reports.sales.view')) {
            return null;
        }

        $totals = Sale::query()->completed()
            ->where('sold_at', '>=', today()->subDays(6))
            ->get(['sold_at', 'total'])
            ->groupBy(fn (Sale $sale) => $sale->sold_at->toDateString())
            ->map(fn ($group) => (int) $group->sum('total'));

        return collect(range(6, 0))->map(fn (int $daysAgo) => [
            'label' => $daysAgo === 0 ? 'Hari ini' : today()->subDays($daysAgo)->translatedFormat('D'),
            'value' => $totals[today()->subDays($daysAgo)->toDateString()] ?? 0,
        ])->all();
    }

    /**
     * @return EloquentCollection<int, Product>|null
     */
    public function lowStock(): ?EloquentCollection
    {
        if (! Auth::user()->can('view-master-data')) {
            return null;
        }

        return Product::query()->where('is_active', true)->lowStock()->orderBy('stock')->limit(6)->get();
    }

    /**
     * @return EloquentCollection<int, Sale>|null
     */
    public function recentSales(): ?EloquentCollection
    {
        $user = Auth::user();

        if (! $user->can('pos.sell')) {
            return null;
        }

        return Sale::query()
            ->with('customer')
            ->when(! $user->can('sales.view'), fn (Builder $query) => $query->where('user_id', $user->id))
            ->latest('sold_at')
            ->latest('id')
            ->limit(6)
            ->get();
    }

    /**
     * @return array<int, array{label: string, icon: string, href: string, hint: string}>
     */
    public function shortcuts(): array
    {
        $user = Auth::user();
        $links = [];

        if ($user->can('manage-master-data')) {
            $links[] = ['label' => 'Produk', 'icon' => 'package', 'href' => route('master-data.products'), 'hint' => 'Tambah produk, ubah harga & barcode'];
        }

        if ($user->can('inventory.manage')) {
            $links[] = ['label' => 'Stok Barang', 'icon' => 'warehouse', 'href' => route('inventory.stock'), 'hint' => 'Catat stok masuk & opname'];
        }

        if ($user->can('pos.sell')) {
            $links[] = ['label' => 'Shift Kasir', 'icon' => 'wallet', 'href' => route('shifts.index'), 'hint' => 'Rekap laci & tutup shift'];
        }

        if (count($links) < 3) {
            $links[] = ['label' => 'Profil Saya', 'icon' => 'user-round', 'href' => route('profile'), 'hint' => 'Ubah nama, kontak, dan password'];
        }

        return $links;
    }

    /**
     * @return Collection<int, Activity>|null
     */
    public function recentActivities(): ?Collection
    {
        if (! Auth::user()->can('viewAny', Activity::class)) {
            return null;
        }

        return Activity::query()->with('causer')->latest('id')->limit(8)->get();
    }

    /**
     * @return array<int, string>
     */
    protected function realtimeEvents(): array
    {
        return ['customer.changed', 'sale.recorded', 'product.changed'];
    }

    public function render()
    {
        $today = $this->today();

        return view('livewire.dashboard', [
            'today' => $today,
            'shift' => Auth::user()->can('pos.sell') ? Auth::user()->openShift() : null,
            'canSell' => Auth::user()->can('pos.sell'),
            'stats' => $this->stats($today),
            'weekChart' => $this->weekChart(),
            'lowStock' => $this->lowStock(),
            'recentSales' => $this->recentSales(),
            'shortcuts' => $this->shortcuts(),
            'activities' => $this->recentActivities(),
            'openShifts' => Auth::user()->can('shifts.manage') ? CashShift::query()->open()->with('user')->get() : collect(),
        ]);
    }
}
