<?php

namespace Database\Factories;

use App\Models\CashShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashShift>
 */
class CashShiftFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'SFT-'.now()->year.'-'.fake()->unique()->numerify('#####'),
            'user_id' => User::factory(),
            'opened_at' => now(),
            'opening_cash' => 200000,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'closed_at' => now(),
            'expected_cash' => 200000,
            'counted_cash' => 200000,
            'cash_difference' => 0,
        ]);
    }
}
