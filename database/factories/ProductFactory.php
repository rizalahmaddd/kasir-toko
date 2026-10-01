<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cost = fake()->numberBetween(2, 80) * 500;

        return [
            'category_id' => null,
            'sku' => 'SKU-'.fake()->unique()->numerify('#####'),
            'barcode' => fake()->unique()->ean13(),
            'name' => ucfirst(fake()->words(3, true)),
            'unit' => 'pcs',
            'cost_price' => $cost,
            'price' => (int) (ceil($cost * 1.25 / 500) * 500),
            'track_stock' => true,
            'stock' => 50,
            'min_stock' => 5,
            'is_active' => true,
        ];
    }

    public function untracked(): static
    {
        return $this->state(['track_stock' => false, 'stock' => 0, 'min_stock' => 0]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
