<?php

use App\Models\CommissionPlan;
use App\Models\Estate;
use App\Models\JournalLine;
use App\Models\Landlord;
use App\Models\LedgerAccount;
use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\PartnerUser;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\PlanTier;
use App\Models\Promotion;
use App\Models\StaffUser;
use App\Models\Unit;
use App\Models\User;
use App\Payments\PaymentService;
use App\Services\CurrentLandlord;
use App\Services\PartnerCommissions;
use App\Services\PartnerProgram;
use App\Support\Settings;
use Illuminate\Support\Facades\Mail;

/*
 * Partners phase 2: revenue share on subscription payments, by commission
 * plan, with a holding period and withholding tax, adjustments and
 * reversals, the books, and the partner's earnings page and statement.
 */

beforeEach(function () {
    Mail::fake();
    config(['payments.default' => 'fake', 'payments.gateways.fake.succeed_after_seconds' => 0]);
    Settings::set(PartnerCommissions::HOLDING_DAYS, 14);
    Settings::set(PartnerCommissions::WHT_PERCENT, 6);
    Settings::set(PartnerCommissions::MIN_PAYOUT, 20000);
});
afterEach(fn () => CurrentLandlord::clear());

/** 20% for 2 months, then 10%, and a UGX 10,000 bonus per landlord. */
function commissionPlan(array $overrides = []): CommissionPlan
{
    return CommissionPlan::create($overrides + ['name' => 'Standard agent', 'base' => 'paid', 'rate_percent' => 20, 'months' => 2, 'later_rate_percent' => 10, 'signup_bonus' => 10000, 'is_active' => true]);
}

/** A partner on $plan, with a landlord they brought in (50,000 a month plan) and the landlord's admin. */
function earningPartner(?CommissionPlan $plan, ?Promotion $promotion = null): array
{
    $partner = Partner::create(['number' => Partner::newNumber(), 'type' => 'agent', 'name' => 'John Okello', 'status' => 'active', 'agreement_accepted_at' => now(),
        'commission_plan_id' => $plan?->id, 'default_promotion_id' => $promotion?->id]);
    $code = $partner->codes()->create(['code' => 'JOHN'.random_int(10, 99), 'is_default' => true, 'is_active' => true]);
    $user = PartnerUser::create(['partner_id' => $partner->id, 'name' => 'John', 'email' => 'john'.random_int(100, 999).'@agents.test', 'password' => 'Secret-pass-123', 'is_active' => true]);

    $landlord = Landlord::factory()->create(['name' => 'Ntinda Flats', 'subscription_status' => 'trialing']);
    app(PartnerProgram::class)->attach($landlord, $code, 'code');
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);
    $sub = Plan::factory()->tiered()->create(['name' => 'Standard']);
    PlanTier::factory()->create(['plan_id' => $sub->id, 'min_units' => 1, 'max_units' => null, 'monthly_price' => 50000, 'quarterly_price' => 140000, 'annual_price' => 500000]);
    CurrentLandlord::runAs($landlord->id, fn () => Unit::create(['name' => 'A1', 'estate_id' => Estate::create(['name' => 'Acacia', 'district' => 'Kampala'])->id, 'status' => 'vacant']));
    CurrentLandlord::clear();

    return compact('partner', 'user', 'landlord', 'admin', 'sub');
}

function payFor($test, array $p): PaymentTransaction
{
    $test->actingAs($p['admin'])->post(route('subscription.pay'), ['plan_id' => $p['sub']->id, 'cycle' => 'monthly', 'phone' => '0772123456']);
    $tx = PaymentTransaction::latest('id')->firstOrFail();
    app(PaymentService::class)->refresh($tx);
    CurrentLandlord::clear();

    return $tx->fresh();
}

function ledgerSum(string $key, string $side): float
{
    return (float) JournalLine::where('ledger_account_id', LedgerAccount::key($key)->id)->sum($side);
}

test('a payment earns the partner their share and the sign-up bonus, less tax, held for the holding period, and booked', function () {
    $p = earningPartner(commissionPlan());

    payFor($this, $p);

    $share = PartnerCommission::where('type', 'share')->sole();
    $bonus = PartnerCommission::where('type', 'bonus')->sole();
    expect($share->only(['base_amount', 'rate_percent', 'gross', 'wht', 'net']))->toBe(['base_amount' => 50000.0, 'rate_percent' => 20.0, 'gross' => 10000.0, 'wht' => 600.0, 'net' => 9400.0])
        ->and($bonus->only(['gross', 'wht', 'net']))->toBe(['gross' => 10000.0, 'wht' => 600.0, 'net' => 9400.0])
        ->and($share->state())->toBe('on_hold')
        ->and($share->available_at->toDateString())->toBe(now()->addDays(14)->toDateString());

    expect(app(PartnerCommissions::class)->balances($p['partner']))->toMatchArray(['on_hold' => 18800.0, 'available' => 0.0, 'earned' => 18800.0, 'wht' => 1200.0]);

    // Books: expense 20,000 = owed to the partner 18,800 + tax 1,200.
    expect(ledgerSum('partner_commissions', 'debit'))->toBe(20000.0)
        ->and(ledgerSum('partner_payable', 'credit'))->toBe(18800.0)
        ->and(ledgerSum('wht_payable', 'credit'))->toBe(1200.0)
        ->and(JournalLine::where('ledger_account_id', LedgerAccount::key('partner_payable')->id)->where('credit', '>', 0)->value('partner_id'))->toBe($p['partner']->id);

    // After the holding period it's available.
    $this->travel(15)->days();
    expect(app(PartnerCommissions::class)->balances($p['partner']))->toMatchArray(['on_hold' => 0.0, 'available' => 18800.0]);
});

test('the rate drops after the plan\'s months, and the bonus is paid only once', function () {
    $p = earningPartner(commissionPlan());
    payFor($this, $p);

    $this->travel(1)->months();
    payFor($this, $p);
    $this->travel(2)->months();
    payFor($this, $p);   // three months after the first payment

    expect(PartnerCommission::where('type', 'share')->orderBy('id')->pluck('rate_percent')->all())->toBe([20.0, 20.0, 10.0])
        ->and(PartnerCommission::where('type', 'bonus')->count())->toBe(1);
});

test('without a plan nothing is earned, until a default plan is set', function () {
    $p = earningPartner(null);
    payFor($this, $p);
    expect(PartnerCommission::count())->toBe(0);

    Settings::set(PartnerCommissions::DEFAULT_PLAN, commissionPlan(['signup_bonus' => 0, 'months' => null])->id);
    payFor($this, $p);
    expect(PartnerCommission::sole()->gross)->toBe(10000.0);
});

test('a discount the partner bears comes off their earnings straight away', function () {
    $promotion = Promotion::create(['name' => 'Agent offer', 'discount_type' => 'percent', 'discount_value' => 20, 'periods' => 1, 'funded_by' => 'partner', 'is_active' => true]);
    $p = earningPartner(commissionPlan(['signup_bonus' => 0, 'months' => null]), $promotion);

    $tx = payFor($this, $p);
    expect((float) $tx->amount)->toBe(40000.0);

    $discount = PartnerCommission::where('type', 'discount')->sole();
    expect($discount->net)->toBe(-10000.0)->and($discount->state())->toBe('available')
        ->and(PartnerCommission::where('type', 'share')->sole()->gross)->toBe(8000.0)   // 20% of what was paid
        ->and(app(PartnerCommissions::class)->balances($p['partner']))->toMatchArray(['on_hold' => 7520.0, 'available' => -10000.0]);

    // The discount was booked once, with the payment.
    expect(ledgerSum('partner_payable', 'debit'))->toBe(10000.0);
});

test('staff set the plans and settings, adjust earnings and reverse a line, with reasons', function () {
    $staff = StaffUser::factory()->withPermissions(['commissions.manage'])->create();

    $this->actingAs($staff, 'staff')->post(route('staff.commissions.plans.store'), ['name' => 'Bad', 'base' => 'paid', 'rate_percent' => 120])->assertSessionHasErrors('rate_percent');
    $this->actingAs($staff, 'staff')->post(route('staff.commissions.plans.store'), ['name' => 'Agency', 'base' => 'paid', 'rate_percent' => 15, 'months' => 12, 'later_rate_percent' => 5, 'is_active' => 1])->assertSessionHas('success');
    $plan = CommissionPlan::sole();
    expect($plan->summary())->toBe('15% of each payment for 12 months, then 5%');

    $this->actingAs($staff, 'staff')->post(route('staff.commissions.settings'), ['holding_days' => 7, 'min_payout' => 50000, 'wht_percent' => 6, 'default_plan_id' => $plan->id])->assertSessionHas('success');
    Settings::flush();
    expect(PartnerCommissions::holdingDays())->toBe(7)->and(PartnerCommissions::minPayout())->toBe(50000.0)->and(PartnerCommissions::defaultPlan()->id)->toBe($plan->id);

    $p = earningPartner(null);   // on the default plan now
    payFor($this, $p);
    $share = PartnerCommission::sole();
    expect($share->gross)->toBe(7500.0)->and($share->available_at->toDateString())->toBe(now()->addDays(7)->toDateString());

    $this->actingAs($staff, 'staff')->post(route('staff.partners.adjust', $p['partner']), ['amount' => 5000])->assertSessionHasErrors('reason');
    $this->actingAs($staff, 'staff')->post(route('staff.partners.adjust', $p['partner']), ['amount' => -2000, 'reason' => 'Duplicate sign-up'])->assertSessionHas('success');

    $this->actingAs($staff, 'staff')->post(route('staff.commissions.reverse', $share), ['reason' => 'Payment refunded'])->assertSessionHas('success');
    $this->actingAs($staff, 'staff')->post(route('staff.commissions.reverse', $share), ['reason' => 'Again'])->assertSessionHas('error');

    expect(app(PartnerCommissions::class)->balances($p['partner'])['earned'])->toBe(-2000.0)
        ->and(ledgerSum('partner_commissions', 'debit') - ledgerSum('partner_commissions', 'credit'))->toBe(-2000.0)
        ->and(ledgerSum('wht_payable', 'credit') - ledgerSum('wht_payable', 'debit'))->toBe(0.0);

    $this->actingAs($staff, 'staff')->get(route('staff.partners.show', $p['partner']))->assertOk()->assertSee('Duplicate sign-up')->assertSee('Reversal');
    $this->actingAs($staff, 'staff')->get(route('staff.commissions.index'))->assertOk()->assertSee('Agency')->assertSee('Default');

    $viewer = StaffUser::factory()->withPermissions(['partners.view'])->create();
    $this->actingAs($viewer, 'staff')->get(route('staff.commissions.index'))->assertOk();
    $this->actingAs($viewer, 'staff')->post(route('staff.partners.adjust', $p['partner']), ['amount' => 1, 'reason' => 'nope'])->assertForbidden();
});

test('the partner sees their earnings, per landlord and on the dashboard, and downloads a monthly statement', function () {
    $p = earningPartner(commissionPlan());
    payFor($this, $p);

    $this->actingAs($p['user'], 'partner')->get(route('partner.earnings'))->assertOk()
        ->assertSee('UGX 18,800')->assertSee('Revenue share')->assertSee('Sign-up bonus')->assertSee('20% of 50,000')->assertSee('On hold until');
    $this->actingAs($p['user'], 'partner')->get(route('partner.dashboard'))->assertOk()->assertSee('Your earnings');
    $this->actingAs($p['user'], 'partner')->get(route('partner.landlords'))->assertOk()->assertSee('18,800');

    $pdf = $this->actingAs($p['user'], 'partner')->get(route('partner.earnings.statement', ['month' => now()->format('Y-m')]));
    $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($pdf->headers->get('content-disposition'))->toContain('primora-partner-statement-'.$p['partner']->number);

    // Only their own lines.
    $other = earningPartner(commissionPlan(['name' => 'Other']));
    $this->actingAs($other['user'], 'partner')->get(route('partner.earnings'))->assertOk()->assertDontSee('Ntinda Flats · subscription');
});
