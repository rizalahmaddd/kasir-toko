<?php

namespace App\Services;

use App\Events\StockThresholdReached;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/**
 * Pengirim notifikasi peringatan stok dengan perlindungan anti-spam (throttling & cache).
 */
class StockAlertNotifier
{
    /**
     * Periksa perubahan stok dan kirim notifikasi jika menyentuh batas kritis.
     */
    public function notifyIfNeeded(Product $product, float $before, float $after): void
    {
        if (! $product->track_stock) {
            return;
        }

        $minStock = (float) $product->min_stock;

        // Jika stok bertambah di atas batas minimum, reset throttle cache agar bisa mengirim peringatan lagi nanti
        if ($after > $minStock) {
            Cache::forget("stock_alert_low_{$product->id}");
            Cache::forget("stock_alert_oos_{$product->id}");

            return;
        }

        $isOutOfStock = $after <= 0;
        $isLowStock = $after <= $minStock;

        if ($isOutOfStock) {
            $cacheKey = "stock_alert_oos_{$product->id}";
            // Kirim jika sebelumnya > 0 atau belum pernah dikirim dalam 12 jam terakhir
            if ($before > 0 || ! Cache::has($cacheKey)) {
                StockThresholdReached::dispatch($product, $after, $minStock, true);
                Cache::put($cacheKey, true, now()->addHours(12));
            }
        } elseif ($isLowStock) {
            $cacheKey = "stock_alert_low_{$product->id}";
            // Kirim jika sebelumnya di atas batas minimum atau belum pernah dikirim dalam 12 jam terakhir
            if ($before > $minStock || ! Cache::has($cacheKey)) {
                StockThresholdReached::dispatch($product, $after, $minStock, false);
                Cache::put($cacheKey, true, now()->addHours(12));
            }
        }
    }
}
