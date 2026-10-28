<?php

use App\Models\Estate;
use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\PlanTier;
use App\Models\StaffUser;
use App\Models\Unit;
use App\Models\User;
use App\Payments\GatewayResult;
use App\Payments\PaymentService;
use App\Services\CurrentLandlord;

/*
 * Landlords choose a unit tier (never below the units they already have);
 * the paid tier becomes their unit limit.
 */

beforeEach(fn () => config(['payments.default' => 'fake']));
afterEach(fn () => CurrentLandlord::clear());

/** Landlord admin with $units units; tiered plan 1–6 / 7–12 / 13+ and a flat plan. */
function tierSetup(int $units = 8, ?int $maxUnits = null): array
{
    $plan = Plan::factory()->tiered()->create(['name' => 'Premium Plan']);
    $small = PlanTier::factory()->create(['plan_id' => $plan->id, 'min_units' => 1, 'max_units' => 6, 'monthly_price' => 15000, 'quarterly_price' => 45000, 'annual_price' => 180000]);
    $medium = PlanTier::factory()->create(['plan_id' => $plan->id, 'min_units' => 7, 'max_units' => 12, 'monthly_price' => 20000, 'quarterly_price' => 60000, 'annual_price' => 240000]);
    $large = PlanTier::factory()->create(['plan_id' => $plan->id, 'min_units' => 13, 'max_units' => null, 'monthly_price' => 30000, 'quarterly_price' => 90000, 'annual_price' => 360000]);

    $landlord = Landlord::factory()->create(['subscription_status' => 'trialing', 'max_units' => $maxUnits]);
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);

    CurrentLandlord::set($landlord->id);
    $estate = Estate::create(['name' => 'Acacia', 'district' => 'Kampala']);
    for ($i = 1; $i <= $units; $i++) {
        Unit::create(['name' => "U{$i}", 'estate_id' => $estate->id, 'status' => 'vacant']);
    }
    CurrentLandlord::clear();

    return compact('plan', 'small', 'medium', 'large', 'landlord', 'admin', 'estate');
}

function payTier($test, User $admin, Plan $plan, ?PlanTier $tier, string $cycle = 'monthly')
{
    return $test->actingAs($admin)->post(route('subscription.pay'), array_filter([
        'plan_id' => $plan->id, 'cycle' => $cycle, 'phone' => '0772123456', 'tier_id' => $tier?->id,
    ]));
}

function succeed(PaymentTransaction $tx): void
{
    app(PaymentService::class)->apply($tx, new GatewayResult(PaymentTransaction::SUCCEEDED, receiptNumber: '1'));
}

// --- Choosing a tier ---

test('tiers smaller than the units already in use are shown but not selectable', function () {
    ['admin' => $admin, 'medium' => $medium] = tierSetup(8);

    $this->actingAs($admin)->get(route('subscription.show'))
        ->assertOk()
        ->assertSee('Number of units')
        ->assertViewHas('plans', function ($plans) use ($medium) {
            $tiers = collect($plans->first()['tiers'])->keyBy('label');

            return $tiers['1–6 units']['eligible'] === false
                && $tiers['7–12 units']['eligible'] === true
                && $tiers['13+ units']['eligible'] === true
                && $plans->first()['default_tier'] === $medium->id;
        });
});

test('a landlord can pay for a larger tier than they use, and it becomes their limit', function () {
    ['admin' => $admin, 'plan' => $plan, 'large' => $large, 'landlord' => $landlord] = tierSetup(8);

    payTier($this, $admin, $plan, $large)->assertRedirect(route('subscription.show'));

    $tx = PaymentTransaction::firstOrFail();
    expect((float) $tx->amount)->toBe(30000.0)
        ->and($tx->context['tier'])->toBe('13+ units')
        ->and($tx->context)->toHaveKey('max_units', null);

    succeed($tx);
    expect($landlord->fresh()->max_units)->toBeNull(); // open-ended top tier: no limit
});

test('paying for a bounded tier sets that many units as the limit', function () {
    ['admin' => $admin, 'plan' => $plan, 'medium' => $medium, 'landlord' => $landlord] = tierSetup(8);

    payTier($this, $admin, $plan, $medium, 'quarterly');
    $tx = PaymentTransaction::firstOrFail();
    expect((float) $tx->amount)->toBe(60000.0);

    succeed($tx);
    expect($landlord->fresh()->max_units)->toBe(12);
});

test('a tier smaller than the units already in use is refused', function () {
    ['admin' => $admin, 'plan' => $plan, 'small' => $small] = tierSetup(8);

    payTier($this, $admin, $plan, $small)
        ->assertSessionHas('error', 'That tier covers up to 6 units, but you already have 8. Please choose a larger tier.');

    expect(PaymentTransaction::count())->toBe(0);
});

test('a tier from a different plan is refused', function () {
    ['admin' => $admin, 'plan' => $plan] = tierSetup(2);
    $otherTier = PlanTier::factory()->create(['min_units' => 1, 'max_units' => 100, 'monthly_price' => 1]);

    payTier($this, $admin, $plan, $otherTier)->assertSessionHas('error');
    expect(PaymentTransaction::count())->toBe(0);
});

test('a flat-price plan has no unit limit', function () {
    ['admin' => $admin, 'landlord' => $landlord] = tierSetup(4, maxUnits: 6);
    $flat = Plan::factory()->create(['name' => 'Flat Plan', 'monthly_price' => 25000]);

    payTier($this, $admin, $flat, null);
    $tx = PaymentTransaction::firstOrFail();
    expect((float) $tx->amount)->toBe(25000.0);

    succeed($tx);
    expect($landlord->fresh()->max_units)->toBeNull();
});

// --- The limit ---

test('a landlord at their unit limit cannot add more units, and is told how to upgrade', function () {
    ['admin' => $admin, 'estate' => $estate, 'landlord' => $landlord] = tierSetup(6, maxUnits: 6);

    $this->actingAs($admin)->post(route('units.store'), ['name' => 'U7', 'status' => 'vacant', 'estate_id' => $estate->id])
        ->assertSessionHasErrors(['units' => 'Your subscription covers up to 6 units, and you already have 6. Upgrade your plan to add more.']);

    expect(DB::table('units')->where('landlord_id', $landlord->id)->count())->toBe(6);

    $this->actingAs($admin)->get(route('units.index'))
        ->assertSee('6 of 6 units used on your subscription.')
        ->assertSee('Upgrade to add more');
});

test('below the limit, units can be added as usual', function () {
    ['admin' => $admin, 'estate' => $estate] = tierSetup(5, maxUnits: 6);

    $this->actingAs($admin)->post(route('units.store'), ['name' => 'U6', 'status' => 'vacant', 'estate_id' => $estate->id])
        ->assertSessionHas('success');
});

test('landlords with no limit (trial or not yet paid for a tier) are never blocked', function () {
    ['admin' => $admin, 'estate' => $estate] = tierSetup(40);

    $this->actingAs($admin)->post(route('units.store'), ['name' => 'U41', 'status' => 'vacant', 'estate_id' => $estate->id])
        ->assertSessionHas('success');
});

test('staff adding units for a landlord hit the same limit', function () {
    ['estate' => $estate, 'landlord' => $landlord] = tierSetup(6, maxUnits: 6);
    $staff = StaffUser::factory()->create();

    $this->actingAs($staff, 'staff')->post(route('staff.landlords.units.store', $landlord), ['name' => 'U7', 'status' => 'vacant', 'estate_id' => $estate->id])
        ->assertSessionHasErrors('units');
});

// --- Staff controls ---

test('staff can raise or clear a landlord\'s unit limit, with the change in the history', function () {
    ['landlord' => $landlord, 'estate' => $estate, 'admin' => $admin] = tierSetup(6, maxUnits: 6);
    $staff = StaffUser::factory()->create();

    $this->actingAs($staff, 'staff')->put(route('staff.subscriptions.update', $landlord), [
        'subscription_status' => 'trialing', 'max_units' => 10, 'reason' => 'Agreed upgrade, invoice to follow',
    ])->assertSessionHas('success');
    expect($landlord->fresh()->max_units)->toBe(10);

    $this->actingAs($staff, 'staff')->get(route('staff.subscriptions.show', $landlord))
        ->assertSee('Unit limit:', false)
        ->assertSee('10 units');

    $this->actingAs($staff, 'staff')->put(route('staff.subscriptions.update', $landlord), [
        'subscription_status' => 'trialing', 'max_units' => '', 'reason' => 'Remove cap for pilot',
    ]);
    expect($landlord->fresh()->max_units)->toBeNull();

    $this->actingAs($admin)->post(route('units.store'), ['name' => 'U7', 'status' => 'vacant', 'estate_id' => $estate->id])
        ->assertSessionHas('success');
});

test('an offline payment records the chosen tier as the limit, and refuses one that is too small', function () {
    ['landlord' => $landlord, 'plan' => $plan, 'small' => $small, 'medium' => $medium] = tierSetup(8);
    $staff = StaffUser::factory()->create();
    $payment = fn (PlanTier $tier) => ['plan_id' => $plan->id, 'cycle' => 'monthly', 'tier_id' => $tier->id, 'amount' => 20000, 'method' => 'cash', 'received_on' => today()->toDateString()];

    $this->actingAs($staff, 'staff')->post(route('staff.subscriptions.payments.store', $landlord), $payment($small))
        ->assertSessionHas('error');
    expect(PaymentTransaction::count())->toBe(0);

    $this->actingAs($staff, 'staff')->post(route('staff.subscriptions.payments.store', $landlord), $payment($medium))
        ->assertSessionHas('success');
    expect($landlord->fresh()->max_units)->toBe(12)
        ->and(PaymentTransaction::first()->context['tier'])->toBe('7–12 units');
});
