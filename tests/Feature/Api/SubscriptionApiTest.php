<?php

use App\Models\Estate;
use App\Models\PartnerCode;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\PlanTier;
use App\Models\Promotion;
use App\Models\Unit;
use App\Models\User;
use App\Services\CurrentLandlord;

/*
 * The mobile app's Plan screen: the landlord's plan, the plans and unit tiers
 * they can choose (with any promotion's discount), paying by mobile money,
 * and adding a partner / promo code.
 */

afterEach(fn () => CurrentLandlord::clear());

/** A tiered plan (1–6 units: 15,000/mo; 7–12: 20,000/mo) and a landlord with 8 units. */
function subApiSetup(): array
{
    $plan = Plan::factory()->tiered()->create(['name' => 'Premium Plan']);
    $small = PlanTier::factory()->create(['plan_id' => $plan->id, 'min_units' => 1, 'max_units' => 6, 'monthly_price' => 15000, 'quarterly_price' => 45000, 'annual_price' => 180000]);
    $large = PlanTier::factory()->create(['plan_id' => $plan->id, 'min_units' => 7, 'max_units' => 12, 'monthly_price' => 20000, 'quarterly_price' => 60000, 'annual_price' => 240000]);

    ['admin' => $admin, 'landlord' => $landlord] = apiLandlord();
    CurrentLandlord::set($landlord->id);
    $estate = Estate::create(['name' => 'Bugolobi', 'district' => 'Kampala']);
    for ($i = 1; $i <= 7; $i++) {
        Unit::create(['name' => "B{$i}", 'estate_id' => $estate->id, 'status' => 'vacant']);
    }
    CurrentLandlord::clear();

    return compact('plan', 'small', 'large', 'admin', 'landlord');
}

test('the plan screen shows the current plan, the plans with tiers too small marked, and cycles', function () {
    ['plan' => $plan, 'small' => $small, 'large' => $large, 'admin' => $admin] = subApiSetup();
    $token = apiToken($this, $admin);

    $r = $this->withToken($token)->getJson(route('api.v1.subscription.show'))->assertOk();
    $r->assertJsonPath('current.state', 'trial')->assertJsonPath('current.units', 8)->assertJsonPath('can_take_code', true)
        ->assertJsonPath('promo', null)->assertJsonCount(3, 'cycles');

    $p = collect($r->json('plans'))->firstWhere('id', $plan->id);
    expect($p['default_tier'])->toBe($large->id)
        ->and(collect($p['tiers'])->firstWhere('id', $small->id)['eligible'])->toBeFalse()
        ->and(collect($p['tiers'])->firstWhere('id', $large->id)['pay']['monthly'])->toEqual(['list' => 20000.0, 'amount' => 20000.0, 'discount' => 0.0]);
});

test('a promo code is added once; the discounted price is shown and charged', function () {
    ['plan' => $plan, 'large' => $large, 'admin' => $admin, 'landlord' => $landlord] = subApiSetup();
    config(['payments.default' => 'fake', 'payments.gateways.fake.succeed_after_seconds' => 0]);
    $promotion = Promotion::create(['name' => 'Launch', 'discount_type' => 'percent', 'discount_value' => 25, 'periods' => 1, 'funded_by' => 'primora', 'is_active' => true]);
    PartnerCode::create(['code' => 'LAUNCH25', 'promotion_id' => $promotion->id, 'is_active' => true]);
    $token = apiToken($this, $admin);

    $this->withToken($token)->postJson(route('api.v1.subscription.code'), ['code' => 'NOPE'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->withToken($token)->postJson(route('api.v1.subscription.code'), ['code' => 'launch25'])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'LAUNCH25'));

    $r = $this->withToken($token)->getJson(route('api.v1.subscription.show'))->assertOk()
        ->assertJsonPath('promo.name', 'Launch')->assertJsonPath('can_take_code', false);
    $tier = collect(collect($r->json('plans'))->firstWhere('id', $plan->id)['tiers'])->firstWhere('id', $large->id);
    expect($tier['pay']['monthly'])->toEqual(['list' => 20000.0, 'amount' => 15000.0, 'discount' => 5000.0]);

    $this->withToken($token)->postJson(route('api.v1.subscription.code'), ['code' => 'LAUNCH25'])->assertUnprocessable()->assertJsonValidationErrors('code');

    $pay = $this->withToken($token)->postJson(route('api.v1.subscription.pay'), ['plan_id' => $plan->id, 'tier_id' => $large->id, 'cycle' => 'monthly', 'phone' => '0772 123 456'])
        ->assertCreated()->json();
    expect((float) PaymentTransaction::where('reference', $pay['reference'])->value('amount'))->toBe(15000.0);
});

test('paying sends a prompt, a second is refused while it waits, and the status poll reports the new period end', function () {
    ['plan' => $plan, 'small' => $small, 'large' => $large, 'admin' => $admin, 'landlord' => $landlord] = subApiSetup();
    config(['payments.default' => 'fake', 'payments.gateways.fake.succeed_after_seconds' => 0]);
    $token = apiToken($this, $admin);
    $url = route('api.v1.subscription.pay');

    $this->withToken($token)->postJson($url, ['plan_id' => $plan->id, 'cycle' => 'monthly', 'phone' => '12'])->assertUnprocessable()->assertJsonValidationErrors('phone');
    $this->withToken($token)->postJson($url, ['plan_id' => $plan->id, 'tier_id' => $small->id, 'cycle' => 'monthly', 'phone' => '0772123456'])
        ->assertUnprocessable()->assertJsonPath('errors.phone.0', fn ($m) => str_contains($m, 'covers up to 6 units'));

    $pay = $this->withToken($token)->postJson($url, ['plan_id' => $plan->id, 'cycle' => 'quarterly', 'phone' => '0772123456'])->assertCreated()->json();
    expect(PaymentTransaction::where('reference', $pay['reference'])->first())
        ->amount->toEqual(60000)->context->tier_id->toBe($large->id);

    $this->withToken($token)->postJson($url, ['plan_id' => $plan->id, 'cycle' => 'monthly', 'phone' => '0772123456'])
        ->assertUnprocessable()->assertJsonPath('errors.phone.0', fn ($m) => str_contains($m, 'already waiting'));

    $this->travel(6)->seconds();
    $status = $this->withToken($token)->getJson(route('api.v1.payments.status', $pay['reference']))->assertOk()
        ->assertJsonPath('status', PaymentTransaction::SUCCEEDED)->json();
    expect($status['period_end'])->not->toBeNull();

    $this->withToken($token)->getJson(route('api.v1.subscription.show'))->assertOk()
        ->assertJsonPath('current.plan', 'Premium Plan')->assertJsonPath('current.state', 'active')->assertJsonPath('current.cycle', 'quarterly')
        ->assertJsonPath('payments.0.reference', $pay['reference'])->assertJsonPath('payments.0.status', PaymentTransaction::SUCCEEDED);
});

test('only admins see the plan, and it stays open while the account is read-only', function () {
    ['plan' => $plan, 'admin' => $admin, 'landlord' => $landlord] = subApiSetup();
    $staff = User::factory()->create(['landlord_id' => $landlord->id, 'role' => 'staff', 'password' => 'Secret-pass-123']);

    $this->withToken(apiToken($this, $staff))->getJson(route('api.v1.subscription.show'))->assertForbidden()->assertJsonPath('code', 'admin_only');

    // A lapsed account can still renew from the app.
    config(['payments.default' => 'fake', 'payments.gateways.fake.succeed_after_seconds' => 0]);
    $landlord->update(['subscription_status' => 'trialing', 'current_period_end' => now()->subDays(40)]);
    $token = apiToken($this, $admin);
    $this->withToken($token)->getJson(route('api.v1.subscription.show'))->assertOk()->assertJsonPath('current.read_only', true)->assertJsonPath('current.state', 'trial_ended');
    $this->withToken($token)->postJson(route('api.v1.subscription.pay'), ['plan_id' => $plan->id, 'cycle' => 'monthly', 'phone' => '0772123456'])->assertCreated();
});
