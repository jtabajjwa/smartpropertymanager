<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Landlord>
 */
class LandlordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => fake()->unique()->e164PhoneNumber(),
            'status' => 'active',
            'subscription_status' => 'active',
            'terms_version' => config('legal.terms_version'),
            'terms_accepted_at' => now(),
        ];
    }

    /** A landlord that hasn't accepted the current Terms of Service (e.g. one created by staff). */
    public function termsNotAccepted(): static
    {
        return $this->state(fn () => ['terms_version' => null, 'terms_accepted_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }
}
