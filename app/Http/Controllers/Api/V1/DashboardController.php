<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\DashboardResource;
use App\Http\Resources\V1\MasterData\ProductResource;
use App\Http\Resources\V1\Sales\SaleResource;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Support\Features;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

#[ApiTag('Beranda', 'Akun')]
class DashboardController extends Controller
{
    /**
     * Beranda: ringkasan angka.
     *
     * Hanya angka yang boleh dilihat akun ini dan fiturnya aktif yang dikirim; bagian yang tidak
     * boleh dilihat bernilai null. `target` menyebut daftar yang dibuka saat kartu diketuk.
     * Penjualan hari ini milik sendiri untuk kasir, semua kasir untuk pemilik/admin.
     */
    public function __invoke(Request $request): DashboardResource
    {
        $user = $request->user();
        $web = app('livewire')->new('dashboard');
        $stats = [];
        $salesOn = Features::enabled('pos.cashier');
        $productsOn = Features::enabled('master-data.products');

        if (Features::enabled('master-data.customers') && $user->can('view-master-data')) {
            $stats[] = [
                'key' => 'customers_active',
                'label' => 'Pelanggan aktif',
                'count' => Customer::where('is_active', true)->count(),
                'target' => ['endpoint' => '/api/v1/master-data/customers', 'query' => ['is_active' => '1']],
            ];
        }

        if ($productsOn && Features::enabled('inventory.stock') && $user->can('view-master-data')) {
            $stats[] = [
                'key' => 'low_stock',
                'label' => 'Stok menipis / habis',
                'count' => Product::query()->where('is_active', true)->lowStock()->count(),
                'target' => ['endpoint' => '/api/v1/inventory/stock', 'query' => ['level' => 'low']],
            ];
        }

        $receivables = null;

        if ($salesOn && Features::enabled('pos.receivables') && $user->can('receivables.manage')) {
            $unpaid = Sale::query()->completed()->where('due_amount', '>', 0);
            $receivables = ['total_due' => (int) (clone $unpaid)->sum('due_amount'), 'count' => (clone $unpaid)->count()];
            $stats[] = [
                'key' => 'receivables_unpaid',
                'label' => 'Kasbon belum lunas',
                'count' => $receivables['count'],
                'target' => ['endpoint' => '/api/v1/receivables', 'query' => (object) []],
            ];
        }

        $today = $salesOn ? $web->today() : null;
        $recentSales = $salesOn && Features::enabled('pos.sales') ? $web->recentSales()?->load(['cashier', 'payments'])->loadCount('items') : null;
        $lowStock = $productsOn ? $web->lowStock()?->load('category') : null;

        return new DashboardResource([
            'date' => today()->toDateString(),
            'unread_notifications' => $user->unreadNotifications()->get()
                ->filter(fn (DatabaseNotification $notification) => Features::allowsUrl($notification->data['url'] ?? null))
                ->count(),
            'stats' => $stats,
            'today' => $today,
            'receivables' => $receivables,
            'week_chart' => $salesOn && Features::enabled('reports.sales') ? $web->weekChart() : null,
            'low_stock' => $lowStock ? ProductResource::collection($lowStock) : null,
            'recent_sales' => $recentSales ? SaleResource::collection($recentSales) : null,
        ]);
    }
}
