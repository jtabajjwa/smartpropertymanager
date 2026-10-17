<?php

namespace Database\Factories;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VendorJob>
 */
class VendorJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'number' => 'JC-' . str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'job_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'status' => 'scheduled',
            'cost' => fake()->randomFloat(2, 20000, 500000),
        ];
    }
}
