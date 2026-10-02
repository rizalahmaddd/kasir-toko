<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Toko '.Str::title(Str::random(6));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'status' => Tenant::STATUS_ACTIVE,
            'plan' => 'pro',
            'onboarded_at' => now(),
        ];
    }

    public function trial(?int $daysLeft = 14): static
    {
        return $this->state(fn () => ['plan' => Tenant::PLAN_TRIAL, 'trial_ends_at' => now()->addDays($daysLeft)]);
    }

    public function pendingOnboarding(): static
    {
        return $this->state(fn () => ['onboarded_at' => null, 'store_type' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => Tenant::STATUS_SUSPENDED]);
    }
}
