<?php

namespace Database\Factories;

use App\Enums\OrderType;
use App\Models\SelfOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SelfOrder>
 */
class SelfOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'SO-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'client_uuid' => (string) Str::uuid(),
            'session_token' => Str::random(64),
            'order_type' => OrderType::TakeAway->value,
            'queue_number' => fake()->unique()->numberBetween(1, 9999),
            'customer_name' => fake()->firstName(),
            'payment_method' => SelfOrder::METHOD_CASHIER,
            'payment_status' => SelfOrder::PAYMENT_UNPAID,
            'status' => SelfOrder::STATUS_PENDING,
            'subtotal' => 0,
            'total' => 0,
        ];
    }

    public function dineIn(string $table = '05'): static
    {
        return $this->state(fn () => ['order_type' => OrderType::DineIn->value, 'table_label' => $table]);
    }

    public function qris(): static
    {
        return $this->state(fn () => ['payment_method' => SelfOrder::METHOD_QRIS, 'payment_status' => SelfOrder::PAYMENT_PENDING_VERIFICATION]);
    }
}
