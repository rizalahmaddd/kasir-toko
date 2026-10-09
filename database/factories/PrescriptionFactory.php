<?php

namespace Database\Factories;

use App\Enums\PrescriptionStatus;
use App\Models\Outlet;
use App\Models\Prescription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prescription>
 */
class PrescriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'outlet_id' => fn () => Outlet::query()->where('is_primary', true)->value('id'),
            'number' => 'RSP-'.fake()->unique()->numerify('#####'),
            'prescription_date' => today(),
            'doctor_name' => fake()->name(),
            'patient_name' => fake()->name(),
            'patient_age' => fake()->numberBetween(1, 80),
            'status' => PrescriptionStatus::Pending,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn () => ['verified_at' => now()]);
    }
}
