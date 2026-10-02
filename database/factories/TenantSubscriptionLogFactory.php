<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantSubscriptionLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantSubscriptionLog>
 */
class TenantSubscriptionLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'action' => TenantSubscriptionLog::ACTION_EXTEND,
            'from_plan' => 'basic',
            'to_plan' => 'basic',
            'from_status' => Tenant::STATUS_ACTIVE,
            'to_status' => Tenant::STATUS_ACTIVE,
            'from_ends_at' => now(),
            'to_ends_at' => now()->addDays(30),
            'amount' => fake()->randomElement([null, 99000, 149000]),
            'note' => null,
        ];
    }
}
