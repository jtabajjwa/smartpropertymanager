<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PlanTier>
 */
class PlanTierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory()->tiered(),
            'min_units' => 1,
            'max_units' => 10,
            'monthly_price' => 50000,
            'quarterly_price' => 135000,
            'annual_price' => 480000,
        ];
    }
}
