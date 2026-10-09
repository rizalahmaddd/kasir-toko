<?php

namespace Database\Factories;

use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use App\Models\Outlet;
use App\Models\StockCount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockCount>
 */
class StockCountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'outlet_id' => fn () => Outlet::query()->where('is_primary', true)->value('id'),
            'number' => 'OPN-'.fake()->unique()->numerify('#####'),
            'status' => StockCountStatus::Counting,
            'scope' => StockCountScope::All,
            'blind_count' => true,
            'hold_adjustments' => true,
            'uncounted_policy' => StockCount::UNCOUNTED_KEEP,
            'started_at' => now(),
        ];
    }

    public function review(): static
    {
        return $this->state(fn () => ['status' => StockCountStatus::Review, 'submitted_at' => now()]);
    }

    public function posted(): static
    {
        return $this->state(fn () => ['status' => StockCountStatus::Posted, 'submitted_at' => now(), 'posted_at' => now()]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => StockCountStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => 'Dibatalkan']);
    }
}
