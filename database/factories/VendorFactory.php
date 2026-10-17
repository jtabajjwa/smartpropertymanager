<?php

namespace Database\Factories;

use App\Models\Landlord;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Vendor>
 */
class VendorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'landlord_id' => Landlord::factory(),
            'name' => fake()->company(),
            'category' => fake()->randomElement(array_keys(Vendor::CATEGORIES)),
            'contact_person' => fake()->name(),
            'phone' => fake()->unique()->e164PhoneNumber(),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->address(),
            'notes' => null,
        ];
    }
}
