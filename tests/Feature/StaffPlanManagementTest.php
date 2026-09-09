<?php

use App\Models\Plan;
use App\Models\StaffUser;

// --- Access Control ---

test('staff user can access plans page', function () {
    $staffUser = StaffUser::factory()->create();

    $this->actingAs($staffUser, 'staff')
        ->get(route('staff.plans.index'))
        ->assertOk();
});

test('guest cannot access plans page', function () {
    $this->get(route('staff.plans.index'))
        ->assertRedirect(route('staff.login'));
});

// --- Create Flat Plan ---

test('staff user can create a flat plan', function () {
    $staffUser = StaffUser::factory()->create();

    $this->actingAs($staffUser, 'staff')
        ->post(route('staff.plans.store'), [
            'name' => 'Standard',
            'pricing_model' => 'flat',
            'monthly_price' => 50000,
            'quarterly_price' => 135000,
            'annual_price' => 480000,
            'currency' => 'ugx',
        ])->assertRedirect()->assertSessionHas('success');

    $this->assertDatabaseHas('plans', [
        'name' => 'Standard',
        'pricing_model' => 'flat',
        'currency' => 'UGX',
    ]);
});

test('plan name must be unique when creating a plan', function () {
    $staffUser = StaffUser::factory()->create();
    Plan::factory()->create(['name' => 'Standard']);

    $this->actingAs($staffUser, 'staff')
        ->post(route('staff.plans.store'), [
            'name' => 'Standard',
            'pricing_model' => 'flat',
            'monthly_price' => 10000,
            'quarterly_price' => 27000,
            'annual_price' => 96000,
            'currency' => 'UGX',
        ])->assertRedirect()->assertSessionHasErrors('name');
});

// --- Create Tiered Plan ---

test('staff user can create a tiered plan with unit-based brackets', function () {
    $staffUser = StaffUser::factory()->create();

    $this->actingAs($staffUser, 'staff')
        ->post(route('staff.plans.store'), [
            'name' => 'Tiered Standard',
            'pricing_model' => 'tiered',
            'currency' => 'UGX',
            'tiers' => [
                ['min_units' => 1, 'max_units' => 10, 'monthly_price' => 50000, 'quarterly_price' => 135000, 'annual_price' => 480000],
                ['min_units' => 11, 'max_units' => 25, 'monthly_price' => 90000, 'quarterly_price' => 243000, 'annual_price' => 864000],
                ['min_units' => 26, 'max_units' => null, 'monthly_price' => 150000, 'quarterly_price' => 405000, 'annual_price' => 1440000],
            ],
        ])->assertRedirect()->assertSessionHas('success');

    $plan = Plan::where('name', 'Tiered Standard')->first();

    expect($plan->pricing_model)->toBe('tiered');
    expect($plan->tiers)->toHaveCount(3);
});

test('tiered plan requires at least one tier', function () {
    $staffUser = StaffUser::factory()->create();

    $this->actingAs($staffUser, 'staff')
        ->post(route('staff.plans.store'), [
            'name' => 'Empty Tiered',
            'pricing_model' => 'tiered',
            'currency' => 'UGX',
        ])->assertRedirect()->assertSessionHasErrors('tiers');
});

test('tiered plan rejects overlapping tier ranges', function () {
    $staffUser = StaffUser::factory()->create();

    $this->actingAs($staffUser, 'staff')
        ->post(route('staff.plans.store'), [
            'name' => 'Overlapping Tiered',
            'pricing_model' => 'tiered',
            'currency' => 'UGX',
            'tiers' => [
                ['min_units' => 1, 'max_units' => 10, 'monthly_price' => 50000, 'quarterly_price' => 135000, 'annual_price' => 480000],
                ['min_units' => 8, 'max_units' => 20, 'monthly_price' => 90000, 'quarterly_price' => 243000, 'annual_price' => 864000],
            ],
        ])->assertRedirect()->assertSessionHasErrors();
});

// --- Update Plan ---

test('staff user can update a flat plan', function () {
    $staffUser = StaffUser::factory()->create();
    $plan = Plan::factory()->create();

    $this->actingAs($staffUser, 'staff')
        ->put(route('staff.plans.update', $plan), [
            'name' => 'Premium',
            'pricing_model' => 'flat',
            'monthly_price' => 80000,
            'quarterly_price' => 216000,
            'annual_price' => 768000,
            'currency' => 'UGX',
        ])->assertRedirect()->assertSessionHas('success');

    expect($plan->fresh()->name)->toBe('Premium');
    expect((float) $plan->fresh()->monthly_price)->toBe(80000.00);
});

test('switching a plan from tiered to flat removes its tiers', function () {
    $staffUser = StaffUser::factory()->create();
    $plan = Plan::factory()->tiered()->create();
    $plan->tiers()->create([
        'min_units' => 1, 'max_units' => null,
        'monthly_price' => 50000, 'quarterly_price' => 135000, 'annual_price' => 480000,
    ]);

    $this->actingAs($staffUser, 'staff')
        ->put(route('staff.plans.update', $plan), [
            'name' => $plan->name,
            'pricing_model' => 'flat',
            'monthly_price' => 60000,
            'quarterly_price' => 162000,
            'annual_price' => 576000,
            'currency' => 'UGX',
        ])->assertRedirect()->assertSessionHas('success');

    expect($plan->fresh()->pricing_model)->toBe('flat');
    expect($plan->fresh()->tiers)->toHaveCount(0);
});

// --- Delete Plan ---

test('staff user can delete a plan', function () {
    $staffUser = StaffUser::factory()->create();
    $plan = Plan::factory()->create();

    $this->actingAs($staffUser, 'staff')
        ->delete(route('staff.plans.destroy', $plan))
        ->assertRedirect()->assertSessionHas('success');

    $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
});

test('deleting a tiered plan also deletes its tiers', function () {
    $staffUser = StaffUser::factory()->create();
    $plan = Plan::factory()->tiered()->create();
    $tier = $plan->tiers()->create([
        'min_units' => 1, 'max_units' => null,
        'monthly_price' => 50000, 'quarterly_price' => 135000, 'annual_price' => 480000,
    ]);

    $this->actingAs($staffUser, 'staff')
        ->delete(route('staff.plans.destroy', $plan));

    $this->assertDatabaseMissing('plan_tiers', ['id' => $tier->id]);
});

// --- Pricing helpers ---

test('flat plan priceFor returns the correct price for each billing cycle', function () {
    $plan = Plan::factory()->create([
        'monthly_price' => 50000,
        'quarterly_price' => 135000,
        'annual_price' => 480000,
    ]);

    expect($plan->priceFor('monthly'))->toBe(50000.0);
    expect($plan->priceFor('quarterly'))->toBe(135000.0);
    expect($plan->priceFor('annual'))->toBe(480000.0);
});

test('tiered plan priceForUnits picks the matching tier', function () {
    $plan = Plan::factory()->tiered()->create();
    $plan->tiers()->createMany([
        ['min_units' => 1, 'max_units' => 10, 'monthly_price' => 50000, 'quarterly_price' => 135000, 'annual_price' => 480000],
        ['min_units' => 11, 'max_units' => 25, 'monthly_price' => 90000, 'quarterly_price' => 243000, 'annual_price' => 864000],
        ['min_units' => 26, 'max_units' => null, 'monthly_price' => 150000, 'quarterly_price' => 405000, 'annual_price' => 1440000],
    ]);

    expect($plan->priceForUnits(5, 'monthly'))->toBe(50000.0);
    expect($plan->priceForUnits(11, 'monthly'))->toBe(90000.0);
    expect($plan->priceForUnits(25, 'monthly'))->toBe(90000.0);
    expect($plan->priceForUnits(100, 'monthly'))->toBe(150000.0);
});

test('tiered plan priceForUnits returns null when no tier covers the unit count', function () {
    $plan = Plan::factory()->tiered()->create();
    $plan->tiers()->create([
        'min_units' => 5, 'max_units' => 10,
        'monthly_price' => 50000, 'quarterly_price' => 135000, 'annual_price' => 480000,
    ]);

    expect($plan->priceForUnits(1, 'monthly'))->toBeNull();
});

test('nextTierAfter returns the next tier above the given unit count', function () {
    $plan = Plan::factory()->tiered()->create();
    $plan->tiers()->createMany([
        ['min_units' => 1, 'max_units' => 10, 'monthly_price' => 50000, 'quarterly_price' => 135000, 'annual_price' => 480000],
        ['min_units' => 11, 'max_units' => 25, 'monthly_price' => 90000, 'quarterly_price' => 243000, 'annual_price' => 864000],
    ]);

    $next = $plan->nextTierAfter(8);

    expect($next->min_units)->toBe(11);
    expect($plan->nextTierAfter(25))->toBeNull();
});
