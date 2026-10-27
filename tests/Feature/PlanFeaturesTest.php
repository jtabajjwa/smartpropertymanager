<?php

use App\Models\Landlord;
use App\Models\Plan;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\CurrentLandlord;
use App\Support\PlanFeatures;

/*
 * Plan features: what each subscription plan includes, upgrade prompts, and
 * features staff grant to one landlord.
 */

afterEach(fn () => CurrentLandlord::clear());

function planLandlord(?array $features, array $attributes = []): array
{
    $plan = Plan::factory()->create(['name' => 'Essentials', 'features' => $features]);
    $landlord = Landlord::factory()->create($attributes + ['plan_id' => $plan->id]);
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);

    return [$landlord, $admin, $plan];
}

test('a plan with no feature list, or no plan at all, has every feature', function () {
    [$landlord] = planLandlord(null);
    $noPlan = Landlord::factory()->create(['plan_id' => null]);

    foreach (array_keys(PlanFeatures::FEATURES) as $feature) {
        expect($landlord->hasFeature($feature))->toBeTrue()->and($noPlan->hasFeature($feature))->toBeTrue();
    }
    expect($landlord->hasFeature('not-a-plan-feature'))->toBeTrue();
});

test('pages for features outside the plan show the upgrade prompt, with the plans that include them', function () {
    [, $admin] = planLandlord(['books']);
    Plan::factory()->create(['name' => 'Professional', 'features' => null]);

    $this->actingAs($admin)->get(route('books.index'))->assertOk();
    $this->actingAs($admin)->get(route('books.trial-balance'))->assertForbidden()
        ->assertSee("Balance sheet &amp; trial balance isn't in your plan", false)->assertSee('Professional')->assertSee('Change plan');
    $this->actingAs($admin)->get(route('books.balance-sheet'))->assertForbidden();
    $this->actingAs($admin)->get(route('vendors.index'))->assertForbidden()->assertSee('Vendors &amp; job cards', false);
    $this->actingAs($admin)->get(route('audits.index'))->assertForbidden();

    // Form posts go back with a message.
    $this->actingAs($admin)->from(route('dashboard'))->post(route('vendors.store'), ['name' => 'Plumber'])
        ->assertRedirect(route('dashboard'))->assertSessionHas('error', 'Vendors & job cards is not included in your plan.');

    // The sidebar shows the locked features.
    $this->actingAs($admin)->get(route('invoices.index'))->assertOk()->assertSee('aria-label="Not in your plan"', false);
});

test('books outside the plan are locked too', function () {
    [, $admin] = planLandlord([]);

    $this->actingAs($admin)->get(route('books.index'))->assertForbidden();
    $this->actingAs($admin)->put(route('books.settings.update'), ['books_basis' => 'cash', 'fiscal_year_start_month' => 7, 'taxpayer_type' => 'individual'])->assertSessionHas('error');
});

test('accrual accounting needs the feature; without it the books stay on cash', function () {
    [$landlord, $admin] = planLandlord(['books'], ['books_basis' => 'accrual']);

    expect($landlord->booksBasis())->toBe('cash');

    $this->actingAs($admin)->get(route('books.settings'))->assertOk()->assertSee('Not in your plan');
    $this->actingAs($admin)->put(route('books.settings.update'), ['books_basis' => 'accrual', 'fiscal_year_start_month' => 7, 'taxpayer_type' => 'individual'])
        ->assertSessionHasErrors('books_basis');

    $landlord->plan->update(['features' => ['books', 'accrual_basis']]);
    expect($landlord->fresh()->booksBasis())->toBe('accrual');
});

test('without team members, a landlord cannot add a second user', function () {
    [, $admin] = planLandlord(['books']);
    $user = ['name' => 'Second', 'email' => 'second@example.test', 'password' => 'password-123', 'role' => 'user'];

    $this->actingAs($admin)->get(route('users.index'))->assertOk()->assertSee('Your plan has one user')->assertDontSee('@click="createModal = true"', false);
    $this->actingAs($admin)->post(route('users.store'), $user)->assertSessionHas('error');
    expect(User::where('email', 'second@example.test')->exists())->toBeFalse();
});

test('staff can grant a landlord a feature their plan leaves out', function () {
    [$landlord, $admin] = planLandlord(['books']);
    $staff = StaffUser::factory()->withPermissions(['landlords.view', 'landlords.edit', 'properties.view'])->create();

    $this->actingAs($staff, 'staff')->get(route('staff.landlords.show', $landlord))->assertOk()->assertSee('Plan features')->assertSee('Not in their plan');
    $this->actingAs($staff, 'staff')->put(route('staff.landlords.features', $landlord), ['extra_features' => ['books_advanced', 'bogus']])->assertSessionHasErrors();
    $this->actingAs($staff, 'staff')->put(route('staff.landlords.features', $landlord), ['extra_features' => ['books_advanced']])->assertRedirect();

    expect($landlord->fresh()->extra_features)->toBe(['books_advanced']);
    $this->actingAs($admin)->get(route('books.trial-balance'))->assertOk();

    $this->actingAs($staff, 'staff')->put(route('staff.landlords.features', $landlord), [])->assertRedirect();
    expect($landlord->fresh()->extra_features)->toBeNull();
});

test('staff choose the features each plan includes', function () {
    $staff = StaffUser::factory()->withPermissions(['plans.view', 'plans.manage'])->create();
    $prices = ['pricing_model' => 'flat', 'currency' => 'UGX', 'monthly_price' => 50000, 'quarterly_price' => 140000, 'annual_price' => 500000];

    $this->actingAs($staff, 'staff')->post(route('staff.plans.store'), $prices + ['name' => 'Essentials', 'features_sent' => '1', 'features' => ['books', 'team']])->assertSessionHasNoErrors();
    $this->actingAs($staff, 'staff')->post(route('staff.plans.store'), $prices + ['name' => 'Professional', 'features_sent' => '1', 'features' => array_keys(PlanFeatures::FEATURES)])->assertSessionHasNoErrors();
    $this->actingAs($staff, 'staff')->post(route('staff.plans.store'), $prices + ['name' => 'Bad', 'features_sent' => '1', 'features' => ['nope']])->assertSessionHasErrors('features.0');

    $essentials = Plan::where('name', 'Essentials')->first();
    expect($essentials->features)->toBe(['books', 'team'])
        ->and(Plan::where('name', 'Professional')->first()->features)->toBeNull(); // all ticked = everything, including future features

    // An update without the feature checkboxes leaves them alone; with none ticked, the plan has none.
    $this->actingAs($staff, 'staff')->put(route('staff.plans.update', $essentials), $prices + ['name' => 'Essentials'])->assertSessionHasNoErrors();
    expect($essentials->fresh()->features)->toBe(['books', 'team']);
    $this->actingAs($staff, 'staff')->put(route('staff.plans.update', $essentials), $prices + ['name' => 'Essentials', 'features_sent' => '1'])->assertSessionHasNoErrors();
    expect($essentials->fresh()->features)->toBe([]);

    $this->actingAs($staff, 'staff')->get(route('staff.plans.index'))->assertOk()->assertSee('0 of '.count(PlanFeatures::FEATURES).' features')->assertSee('All features');
});

test('the subscription page shows what each plan includes', function () {
    [$landlord, $admin] = planLandlord(['books']);
    Plan::factory()->create(['name' => 'Professional', 'features' => null]);

    $this->actingAs($admin)->get(route('subscription.show'))->assertOk()
        ->assertSee('trial balance', false)->assertSee('missing', false)->assertSee('Includes every feature');
});
