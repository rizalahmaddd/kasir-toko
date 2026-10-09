<?php

namespace Database\Factories;

use App\Models\Outlet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Outlet>
 */
class OutletFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Outlet '.Str::title(Str::random(6)),
            'code' => Str::upper(Str::random(4)),
            'is_primary' => false,
            'is_active' => true,
            'priority' => 0,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => ['name' => 'Pusat', 'code' => Outlet::DEFAULT_CODE, 'is_primary' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
