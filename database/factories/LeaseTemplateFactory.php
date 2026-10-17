<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LeaseTemplate>
 */
class LeaseTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'content' => "This agreement is between {{landlord_name}} and {{tenant_name}} for {{unit_name}} at {{estate_name}}, starting {{start_date}} at a rent of {{rent_amount}} per {{billing_cycle}} period.",
            'is_default' => false,
        ];
    }
}
