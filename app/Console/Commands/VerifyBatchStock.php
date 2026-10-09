<?php

namespace App\Console\Commands;

use App\Models\ProductStock;
use App\Services\Pos\BatchService;
use App\Support\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VerifyBatchStock extends Command
{
    protected $signature = 'stock:verify-batches {--fix : Samakan saldo batch dengan stok outlet lewat batch penyesuaian}';

    protected $description = 'Periksa stok produk ber-batch: jumlah batch per outlet harus sama dengan stok outlet, dan stok semua outlet sama dengan products.stock.';

    public function handle(): int
    {
        // Lintas toko, jadi lewat query builder dengan join manual (tanpa global scope tenant).
        $batchDrift = DB::table('product_stocks')
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->leftJoinSub(
                DB::table('product_batches')->selectRaw('product_id, outlet_id, SUM(quantity) as batch_total')->groupBy('product_id', 'outlet_id'),
                'batches',
                fn ($join) => $join->on('batches.product_id', '=', 'product_stocks.product_id')->on('batches.outlet_id', '=', 'product_stocks.outlet_id'),
            )
            ->where('products.track_batch', true)
            ->where('products.track_stock', true)
            ->whereRaw('ABS(product_stocks.stock - COALESCE(batches.batch_total, 0)) > 0.0005')
            ->get(['product_stocks.id', 'products.tenant_id', 'product_stocks.product_id', 'product_stocks.outlet_id', 'product_stocks.stock', DB::raw('COALESCE(batches.batch_total, 0) as batch_total')]);

        $totalDrift = DB::table('products')
            ->leftJoinSub(DB::table('product_stocks')->selectRaw('product_id, SUM(stock) as outlet_total')->groupBy('product_id'), 'totals', 'totals.product_id', '=', 'products.id')
            ->where('products.track_stock', true)
            ->whereRaw('ABS(products.stock - COALESCE(totals.outlet_total, 0)) > 0.0005')
            ->get(['products.id', 'products.tenant_id', 'products.stock', DB::raw('COALESCE(totals.outlet_total, 0) as outlet_total')]);

        foreach ($batchDrift as $row) {
            $this->warn("Toko {$row->tenant_id} produk {$row->product_id} outlet {$row->outlet_id}: stok {$row->stock}, batch {$row->batch_total}");
        }

        foreach ($totalDrift as $row) {
            $this->warn("Toko {$row->tenant_id} produk {$row->id}: products.stock {$row->stock}, jumlah outlet {$row->outlet_total}");
        }

        if ($batchDrift->isNotEmpty() || $totalDrift->isNotEmpty()) {
            Log::warning('Selisih stok ditemukan oleh stock:verify-batches.', ['batch' => $batchDrift->count(), 'total' => $totalDrift->count()]);
        }

        if ($this->option('fix') && $batchDrift->isNotEmpty()) {
            $this->fix($batchDrift->pluck('id')->all());
        }

        $this->info("Selesai: {$batchDrift->count()} selisih batch, {$totalDrift->count()} selisih total.");

        return $batchDrift->isEmpty() && $totalDrift->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<int>  $stockIds
     */
    private function fix(array $stockIds): void
    {
        $service = app(BatchService::class);

        foreach (ProductStock::query()->withoutGlobalScopes()->with(['product' => fn ($query) => $query->withoutGlobalScopes()])->whereIn('id', $stockIds)->get() as $stock) {
            app(CurrentTenant::class)->run($stock->tenant_id, fn () => $service->reconcileProduct($stock->product));
        }
    }
}
