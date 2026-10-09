<?php

namespace Database\Factories;

use App\Models\StockCountEntry;
use App\Models\StockCountItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockCountEntry>
 */
class StockCountEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_count_item_id' => StockCountItem::factory(),
            'client_uuid' => (string) Str::uuid(),
            'quantity_base' => fake()->numberBetween(1, 20),
            'system_qty_at_count' => 10,
            'counted_at' => now(),
            'source' => StockCountEntry::SOURCE_WEB,
        ];
    }

    public function voided(): static
    {
        return $this->state(fn () => ['voided_at' => now()]);
    }
}
