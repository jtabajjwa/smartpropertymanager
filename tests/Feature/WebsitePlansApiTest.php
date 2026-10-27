<?php

use App\Models\Plan;
use App\Models\PlanTier;
use App\Models\StaffUser;

const WEBSITE_KEY = 'test-website-key-0123456789abcdef';

beforeEach(function () {
    config(['services.website.api_key' => WEBSITE_KEY]);
});

function websitePlans($test, ?string $key = WEBSITE_KEY)
{
    return $test->getJson(route('api.website.plans'), $key === null ? [] : ['X-Website-Key' => $key]);
}

// --- Access ---

test('the plans API is off when no key is configured', function () {
    config(['services.website.api_key' => null]);

    websitePlans($this)->assertStatus(503);
});

test('the plans API refuses a missing or wrong key', function () {
    websitePlans($this, null)->assertUnauthorized();
    websitePlans($this, 'not-the-key')->assertUnauthorized();
    websitePlans($this, '')->assertUnauthorized();
});

test('the plans API is rate limited', function () {
    foreach (range(1, 60) as $i) {
        websitePlans($this)->assertOk();
    }

    websitePlans($this)->assertStatus(429);
});

// --- Content ---

test('only active plans marked for the website are published, with their tiers', function () {
    $published = Plan::factory()->tiered()->create(['name' => 'Premium', 'show_on_website' => true]);
    PlanTier::factory()->create(['plan_id' => $published->id, 'min_units' => 7, 'max_units' => 12, 'monthly_price' => 20000, 'quarterly_price' => 60000, 'annual_price' => 240000]);
    PlanTier::factory()->create(['plan_id' => $published->id, 'min_units' => 1, 'max_units' => 6, 'monthly_price' => 15000, 'quarterly_price' => 45000, 'annual_price' => 180000]);

    Plan::factory()->create(['name' => 'Hidden Plan', 'show_on_website' => false]);
    Plan::factory()->create(['name' => 'Retired Plan', 'show_on_website' => true, 'is_active' => false]);

    websitePlans($this)
        ->assertOk()
        ->assertJsonCount(1, 'plans')
        ->assertJsonPath('plans.0.name', 'Premium')
        ->assertJsonPath('plans.0.pricing_model', 'tiered')
        ->assertJsonPath('plans.0.currency', 'UGX')
        ->assertJsonPath('plans.0.tiers', [
            ['min_units' => 1, 'max_units' => 6, 'monthly' => 15000, 'quarterly' => 45000, 'annual' => 180000],
            ['min_units' => 7, 'max_units' => 12, 'monthly' => 20000, 'quarterly' => 60000, 'annual' => 240000],
        ])
        ->assertJsonMissing(['name' => 'Hidden Plan'])
        ->assertJsonMissing(['name' => 'Retired Plan']);
});

test('a flat plan is published as one open-ended tier', function () {
    Plan::factory()->create(['name' => 'Basic', 'show_on_website' => true, 'monthly_price' => 25000, 'quarterly_price' => 75000, 'annual_price' => 300000]);

    websitePlans($this)
        ->assertOk()
        ->assertJsonPath('plans.0.tiers', [
            ['min_units' => 1, 'max_units' => null, 'monthly' => 25000, 'quarterly' => 75000, 'annual' => 300000],
        ]);
});

test('only public pricing fields are exposed', function () {
    Plan::factory()->create(['show_on_website' => true]);

    $plan = websitePlans($this)->assertOk()->json('plans.0');

    expect(array_keys($plan))->toBe(['name', 'pricing_model', 'currency', 'features', 'tiers'])
        ->and($plan['features'])->toBe(collect(\App\Support\PlanFeatures::FEATURES)->pluck(0)->all());

    Plan::factory()->create(['show_on_website' => true, 'features' => ['books']]);
    $response = websitePlans($this)->assertOk();
    expect($response->json('plans.1.features'))->toBe(['Books & taxes'])
        ->and($response->json('feature_catalogue'))->toHaveCount(count(\App\Support\PlanFeatures::FEATURES));
});

// --- Staff control ---

test('staff choose which plans appear on the website', function () {
    $staff = StaffUser::factory()->create();

    $this->actingAs($staff, 'staff')->post(route('staff.plans.store'), [
        'name' => 'Starter', 'pricing_model' => 'flat', 'currency' => 'ugx',
        'monthly_price' => 10000, 'quarterly_price' => 30000, 'annual_price' => 120000,
        'show_on_website' => '1',
    ])->assertSessionHas('success');

    $plan = Plan::where('name', 'Starter')->firstOrFail();
    expect($plan->show_on_website)->toBeTrue();

    $this->actingAs($staff, 'staff')->put(route('staff.plans.update', $plan), [
        'name' => 'Starter', 'pricing_model' => 'flat', 'currency' => 'UGX',
        'monthly_price' => 10000, 'quarterly_price' => 30000, 'annual_price' => 120000,
        // checkbox unticked: field absent
    ])->assertSessionHas('success');

    expect($plan->fresh()->show_on_website)->toBeFalse();
    websitePlans($this)->assertJsonCount(0, 'plans');
});
