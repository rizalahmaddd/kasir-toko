<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'CUST-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->company(),
            'type' => fake()->randomElement(['Retail', 'Distributor', 'Korporat']),
            'contact_person' => fake()->name(),
            'phone' => fake()->numerify('08##########'),
            'email' => fake()->companyEmail(),
            'address' => fake()->address(),
            'npwp' => fake()->numerify('##.###.###.#-###.###'),
            'payment_term_days' => fake()->randomElement([0, 14, 30, 45]),
            'is_active' => true,
        ];
    }
}
