<?php

namespace Database\Factories;

use App\Models\BackupSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupSchedule>
 */
class BackupScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Backup harian',
            'scope' => 'database',
            'frequency' => 'daily',
            'times' => ['02:00'],
            'weekdays' => null,
            'month_day' => null,
            'keep' => 7,
            'include_secrets' => false,
            'is_active' => true,
        ];
    }

    public function weekly(array $weekdays, array $times = ['02:00']): static
    {
        return $this->state(fn () => ['frequency' => 'weekly', 'weekdays' => $weekdays, 'times' => $times]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
