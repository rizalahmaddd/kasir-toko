<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockCountItem>
 */
class StockCountItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_count_id' => StockCount::factory(),
            'product_id' => Product::factory(),
            'expected_qty' => 10,
        ];
    }

    public function counted(float $quantity): static
    {
        return $this->state(fn () => ['counted_qty' => $quantity, 'reference_at' => now()]);
    }
}
