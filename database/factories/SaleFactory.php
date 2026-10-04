<?php

namespace Database\Factories;

use App\Enums\SaleStatus;
use App\Models\CashShift;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $total = fake()->numberBetween(5, 200) * 1000;

        return [
            'number' => 'TRX-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'client_uuid' => (string) Str::uuid(),
            'cash_shift_id' => CashShift::factory(),
            'user_id' => User::factory(),
            'status' => SaleStatus::Completed,
            'subtotal' => $total,
            'total' => $total,
            'paid_amount' => $total,
            'sold_at' => now(),
        ];
    }
}
