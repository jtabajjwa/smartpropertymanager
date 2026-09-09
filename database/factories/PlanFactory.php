<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'pricing_model' => 'flat',
            'monthly_price' => 50000,
            'quarterly_price' => 135000,
            'annual_price' => 480000,
            'currency' => 'UGX',
            'is_active' => true,
        ];
    }

    public function tiered(): static
    {
        return $this->state(fn () => [
            'pricing_model' => 'tiered',
            'monthly_price' => 0,
            'quarterly_price' => 0,
            'annual_price' => 0,
        ]);
    }
}
