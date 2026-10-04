<?php

namespace App\Events;

use App\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dipicu saat stok produk menyentuh batas menipis (low stock) atau habis (out of stock).
 */
class StockThresholdReached
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Product $product,
        public float $currentStock,
        public float $minStock,
        public bool $isOutOfStock = false
    ) {}
}
