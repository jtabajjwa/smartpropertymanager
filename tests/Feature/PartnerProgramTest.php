<?php

use App\Mail\NoticeMail;
use App\Models\Estate;
use App\Models\JournalLine;
use App\Models\Landlord;
use App\Models\LedgerAccount;
use App\Models\Partner;
use App\Models\PartnerCode;
use App\Models\PartnerUser;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\PlanTier;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\StaffUser;
use App\Models\Unit;
use App\Models\User;
use App\Payments\PaymentService;
use App\Services\CurrentLandlord;
use Illuminate\Support\Facades\Mail;

/*
 * Marketing partners and agents, phase 1: applying and approval, the partner
 * portal, codes and agent numbers, linking landlords, and promotions that
 * discount subscriptions (with the discount in the books).
 */

beforeEach(function () {
    Mail::fake();
    config(['payments.default' => 'fake', 'payments.gateways.fake.succeed_after_seconds' => 0]);
});
afterEach(fn () => CurrentLandlord::clear());

function partnerPromo(array $attributes = []): Promotion
{
    return Promotion::create($attributes + ['name' => 'Agent offer', 'discount_type' => 'percent', 'discount_value' => 20, 'periods' => 2, 'funded_by' => 'primora', 'is_active' => true]);
}

/** An approved partner who has accepted the agreement, with a sign-in and main code JOHN24. */
function approvedPartner(?Promotion $promotion = null, string $email = 'john@agents.test'): Partner
{
    $partner = Partner::create(['number' => Partner::newNumber(), 'type' => 'agent', 'name' => 'John Okello', 'contact_name' => 'John Okello', 'status' => 'active',
        'agreement_accepted_at' => now(), 'approved_at' => now(), 'default_promotion_id' => $promotion?->id]);
    $partner->codes()->create(['code' => 'JOHN'.random_int(10, 99), 'is_default' => true, 'is_active' => true]);
    PartnerUser::create(['partner_id' => $partner->id, 'name' => 'John Okello', 'email' => $email, 'password' => 'Secret-pass-123', 'is_active' => true]);

    return $partner;
}

function partnerSignupData(array $overrides = []): array
{
    return $overrides + [
        'company_name' => 'Kololo Homes', 'name' => 'Grace Namata', 'email' => 'grace'.random_int(1000, 9999).'@homes.test',
        'phone' => '+25677'.random_int(1000000, 9999999), 'password' => 'Str0ng-Pass!word', 'password_confirmation' => 'Str0ng-Pass!word', 'terms' => 1,
    ];
}

/** A flat plan at 50,000 a month, and a landlord admin with a unit. */
function partnerPayingLandlord(Landlord $landlord): array
{
    $plan = Plan::factory()->tiered()->create(['name' => 'Standard']);
    PlanTier::factory()->create(['plan_id' => $plan->id, 'min_units' => 1, 'max_units' => null, 'monthly_price' => 50000, 'quarterly_price' => 140000, 'annual_price' => 500000]);
    $admin = User::where('landlord_id', $landlord->id)->first() ?? User::factory()->admin()->create(['landlord_id' => $landlord->id]);
    CurrentLandlord::runAs($landlord->id, fn () => Unit::create(['name' => 'A1', 'estate_id' => Estate::create(['name' => 'Acacia', 'district' => 'Kampala'])->id, 'status' => 'vacant']));
    CurrentLandlord::clear();

    return [$admin, $plan];
}

function partnerPaySubscription($test, User $admin, Plan $plan): PaymentTransaction
{
    $test->actingAs($admin)->post(route('subscription.pay'), ['plan_id' => $plan->id, 'cycle' => 'monthly', 'phone' => '0772123456'])->assertRedirect(route('subscription.show'));
    $tx = PaymentTransaction::latest('id')->firstOrFail();
    app(PaymentService::class)->refresh($tx);
    CurrentLandlord::clear();

    return $tx->fresh();
}

// --- Applying and approval ---------------------------------------------------------

test('an agent applies, waits for approval, and is approved by staff with a code and agent number', function () {
    $staff = StaffUser::factory()->withPermissions(['partners.manage'])->create();

    $this->post(route('partner.apply'), ['type' => 'agent', 'name' => 'John Okello', 'contact_name' => 'John Okello', 'email' => 'john@agents.test', 'phone' => '0772555111',
        'id_type' => 'national_id', 'id_number' => 'CM900', 'password' => 'Str0ng-Pass!word', 'password_confirmation' => 'Str0ng-Pass!word', 'agreement' => 1])
        ->assertRedirect(route('partner.dashboard'));

    $partner = Partner::sole();
    expect($partner->status)->toBe('pending')->and($partner->number)->toMatch('/^\d{4}$/')->and($partner->agreement_accepted_at)->not->toBeNull();
    Mail::assertSent(NoticeMail::class, fn ($m) => $m->hasTo($staff->email) && str_contains($m->subjectLine, 'Partner application'));

    $this->get(route('partner.dashboard'))->assertOk()->assertSee('Your application is being reviewed');
    $this->get(route('partner.landlords'))->assertRedirect(route('partner.dashboard'));

    $this->actingAs($staff, 'staff')->post(route('staff.partners.status', $partner), ['status' => 'active'])->assertSessionHas('success');
    $partner->refresh();
    expect($partner->status)->toBe('active')->and($partner->approved_by_staff_user_id)->toBe($staff->id)->and($partner->defaultCode()->code)->toStartWith('JOHN');
    Mail::assertSent(NoticeMail::class, fn ($m) => $m->hasTo('john@agents.test') && str_contains($m->subjectLine, 'now a'));

    $this->actingAs(PartnerUser::sole(), 'partner')->get(route('partner.codes'))->assertOk()->assertSee($partner->defaultCode()->code)->assertSee($partner->number);
});

test('staff can turn down or suspend a partner, with a reason; they can no longer sign in and their codes stop working', function () {
    $staff = StaffUser::factory()->withPermissions(['partners.manage'])->create();
    $partner = approvedPartner();

    $this->actingAs($staff, 'staff')->post(route('staff.partners.status', $partner), ['status' => 'suspended'])->assertSessionHas('error');
    $this->actingAs($staff, 'staff')->post(route('staff.partners.status', $partner), ['status' => 'suspended', 'note' => 'Misleading adverts'])->assertSessionHas('success');

    $this->actingAs(PartnerUser::sole(), 'partner')->get(route('partner.dashboard'))->assertRedirect(route('partner.login'));
    expect(app(\App\Services\PartnerProgram::class)->resolve($partner->defaultCode()->code))->toBeNull()
        ->and(app(\App\Services\PartnerProgram::class)->resolve($partner->number))->toBeNull();
});

test('a partner added by staff gets a temporary password, must change it, and accepts the agreement before signing landlords up', function () {
    $staff = StaffUser::factory()->withPermissions(['partners.manage'])->create();
    $this->actingAs($staff, 'staff')->post(route('staff.partners.store'), ['type' => 'marketing', 'name' => 'Ads Uganda Ltd', 'contact_name' => 'Ruth', 'user_email' => 'ruth@ads.test'])
        ->assertRedirect();

    $partner = Partner::sole();
    $user = PartnerUser::sole();
    expect($partner->status)->toBe('active')->and($user->must_change_password)->toBeTrue()->and($partner->defaultCode())->not->toBeNull();
    Mail::assertSent(NoticeMail::class, fn ($m) => $m->hasTo('ruth@ads.test') && collect($m->lines)->contains(fn ($l) => str_starts_with($l, 'Temporary password:')));

    $this->actingAs($user, 'partner')->get(route('partner.dashboard'))->assertRedirect(route('partner.password'));
    $user->update(['must_change_password' => false]);
    $this->actingAs($user, 'partner')->get(route('partner.landlords.create'))->assertRedirect(route('partner.agreement'));
    $this->actingAs($user, 'partner')->post(route('partner.agreement.accept'), ['agreement' => 1])->assertRedirect(route('partner.dashboard'));
    $this->actingAs($user, 'partner')->get(route('partner.landlords.create'))->assertOk();
});

// --- Linking landlords -------------------------------------------------------------

test('a landlord who follows a partner\'s link is linked to them, with the offer', function () {
    $partner = approvedPartner(partnerPromo());
    $code = $partner->defaultCode()->code;

    $this->get(route('signup', ['ref' => strtolower($code)]))->assertOk()->assertSee("Signing up with code")->assertSee('20% off your first 2 paid periods')
        ->assertCookie(\App\Http\Controllers\SignupController::REF_COOKIE, $code);

    $this->post(route('signup'), partnerSignupData(['partner_code' => $code]));
    $landlord = Landlord::latest('id')->first();
    expect($landlord->partner_id)->toBe($partner->id)->and($landlord->partner_source)->toBe('code')
        ->and($landlord->promotion_id)->toBe($partner->default_promotion_id)->and($landlord->partner_locked_at)->toBeNull();
});

test('the agent number works as a code, and an unknown code is explained', function () {
    $partner = approvedPartner();

    $this->post(route('signup'), partnerSignupData(['partner_code' => 'NOPE99']))->assertSessionHasErrors('partner_code');
    expect(Landlord::count())->toBe(0);

    $this->post(route('signup'), partnerSignupData(['partner_code' => $partner->number]));
    expect(Landlord::sole()->partner_id)->toBe($partner->id);
});

test('a partner signs a landlord up; the landlord sets their own password and accepts the terms, and only that partner sees them', function () {
    $partner = approvedPartner(partnerPromo());
    $other = approvedPartner(null, 'other@agents.test');
    $user = $partner->users()->first();

    $this->actingAs($user, 'partner')->post(route('partner.landlords.store'), ['company_name' => 'Ntinda Flats', 'name' => 'Peter Mukasa', 'email' => 'peter@flats.test', 'phone' => '0701234567'])
        ->assertSessionHasErrors('consent');
    $this->actingAs($user, 'partner')->post(route('partner.landlords.store'), ['company_name' => 'Ntinda Flats', 'name' => 'Peter Mukasa', 'email' => 'peter@flats.test', 'phone' => '0701234567', 'consent' => 1])
        ->assertRedirect(route('partner.landlords'));

    $landlord = Landlord::sole();
    expect($landlord->partner_id)->toBe($partner->id)->and($landlord->partner_source)->toBe('partner')->and($landlord->promotion_id)->not->toBeNull()
        ->and($landlord->subscription_status)->toBe('trialing')->and($landlord->hasAcceptedCurrentTerms())->toBeFalse()
        ->and(User::sole()->role)->toBe('admin');
    Mail::assertSent(NoticeMail::class, fn ($m) => $m->hasTo('peter@flats.test') && str_contains(implode(' ', $m->lines), 'John Okello has set up'));

    $this->actingAs($user, 'partner')->get(route('partner.landlords'))->assertOk()->assertSee('Ntinda Flats')->assertSee('peter@flats.test')->assertSee('Peter Mukasa');
    $this->actingAs($other->users()->first(), 'partner')->get(route('partner.landlords'))->assertOk()->assertDontSee('Ntinda Flats');
    $this->actingAs($user, 'partner')->get(route('partner.dashboard'))->assertOk()->assertSee('Ntinda Flats');
});

test('staff link a landlord to a partner, or unlink them, with a reason', function () {
    $staff = StaffUser::factory()->withPermissions(['partners.manage'])->create();
    $partner = approvedPartner();
    $landlord = Landlord::factory()->create();

    $this->actingAs($staff, 'staff')->post(route('staff.partners.landlords.assign', $partner), ['landlord_id' => $landlord->id])->assertSessionHasErrors('reason');
    $this->actingAs($staff, 'staff')->post(route('staff.partners.landlords.assign', $partner), ['landlord_id' => $landlord->id, 'reason' => 'Signed at the expo'])->assertSessionHas('success');
    expect($landlord->fresh()->partner_id)->toBe($partner->id)->and($landlord->fresh()->partner_source)->toBe('staff');

    $this->actingAs($staff, 'staff')->get(route('staff.partners.show', $partner))->assertOk()->assertSee($landlord->name)->assertSee('Assigned by staff');

    $this->actingAs($staff, 'staff')->post(route('staff.partners.landlords.remove', [$partner, $landlord]), ['reason' => 'Wrong partner'])->assertSessionHas('success');
    expect($landlord->fresh()->partner_id)->toBeNull();

    $viewer = StaffUser::factory()->withPermissions(['partners.view'])->create();
    $this->actingAs($viewer, 'staff')->get(route('staff.partners.show', $partner))->assertOk()->assertDontSee('+ Link a landlord');
    $this->actingAs($viewer, 'staff')->post(route('staff.partners.landlords.assign', $partner), ['landlord_id' => $landlord->id, 'reason' => 'x x x'])->assertForbidden();
});

// --- Discounts ----------------------------------------------------------------------

test('the promotion discounts the first paid periods, is recorded, posts to the books, and fixes the partner link', function () {
    $partner = approvedPartner(partnerPromo(['funded_by' => 'split', 'partner_share_percent' => 50]));
    $landlord = Landlord::factory()->create(['subscription_status' => 'trialing']);
    app(\App\Services\PartnerProgram::class)->attach($landlord, $partner->defaultCode(), 'code');
    [$admin, $plan] = partnerPayingLandlord($landlord);

    $this->actingAs($admin)->get(route('subscription.show'))->assertOk()->assertSee('Agent offer')->assertSee('2 discounted payments left');
    CurrentLandlord::clear();

    $tx = partnerPaySubscription($this, $admin, $plan);
    expect((float) $tx->amount)->toBe(40000.0)->and($tx->status)->toBe('succeeded')
        ->and($tx->context)->toMatchArray(['list_amount' => 50000, 'discount' => 10000, 'partner_share' => 5000, 'primora_share' => 5000, 'period_number' => 1]);

    $landlord->refresh();
    expect($landlord->promotion_periods_used)->toBe(1)->and($landlord->partner_locked_at)->not->toBeNull()
        ->and(PromotionRedemption::sole()->only(['discount_amount', 'partner_share', 'partner_id']))->toBe(['discount_amount' => 10000.0, 'partner_share' => 5000.0, 'partner_id' => $partner->id]);

    // Books: income at full price; Primora's half of the discount an expense; the partner's half off what we owe them.
    $line = fn (string $key) => JournalLine::where('ledger_account_id', LedgerAccount::key($key)->id);
    expect((float) $line('subscription_income')->sum('credit'))->toBe(50000.0)
        ->and((float) $line('promo_discounts')->sum('debit'))->toBe(5000.0)
        ->and((float) $line('partner_payable')->sum('debit'))->toBe(5000.0)
        ->and($line('partner_payable')->value('partner_id'))->toBe($partner->id);

    // The second payment is discounted too; the third isn't.
    expect((float) partnerPaySubscription($this, $admin, $plan)->amount)->toBe(40000.0)
        ->and((float) partnerPaySubscription($this, $admin, $plan)->amount)->toBe(50000.0);

    // The link is fixed: a code can't be added any more.
    $this->actingAs($admin)->post(route('subscription.code'), ['code' => 'ANY123'])->assertSessionHas('error');
});

test('a landlord can add a campaign code on the subscription page before their first payment; Primora bears it', function () {
    $staff = StaffUser::factory()->withPermissions(['promotions.manage'])->create();
    $this->actingAs($staff, 'staff')->post(route('staff.promotions.store'), ['name' => 'Launch', 'discount_type' => 'percent', 'discount_value' => 150, 'periods' => 1, 'funded_by' => 'primora', 'is_active' => 1])
        ->assertSessionHasErrors('discount_value');
    $this->actingAs($staff, 'staff')->post(route('staff.promotions.store'), ['name' => 'Launch', 'discount_type' => 'fixed', 'discount_value' => 15000, 'periods' => 1, 'funded_by' => 'primora', 'is_active' => 1])
        ->assertSessionHas('success');
    $this->actingAs($staff, 'staff')->post(route('staff.promotions.codes.store'), ['code' => 'launch-15', 'promotion_id' => Promotion::sole()->id])->assertSessionHas('success');

    $landlord = Landlord::factory()->create(['subscription_status' => 'trialing']);
    [$admin, $plan] = partnerPayingLandlord($landlord);

    $this->actingAs($admin)->get(route('subscription.show'))->assertOk()->assertSee('Have a partner or promo code?');
    CurrentLandlord::clear();
    $this->actingAs($admin)->post(route('subscription.code'), ['code' => 'NOPE'])->assertSessionHasErrors('code');
    CurrentLandlord::clear();
    $this->actingAs($admin)->post(route('subscription.code'), ['code' => 'launch-15'])->assertSessionHas('success');
    CurrentLandlord::clear();

    $landlord->refresh();
    expect($landlord->partner_id)->toBeNull()->and($landlord->promotion_id)->toBe(Promotion::sole()->id);

    $tx = partnerPaySubscription($this, $admin, $plan);
    expect((float) $tx->amount)->toBe(35000.0)
        ->and((float) JournalLine::where('ledger_account_id', LedgerAccount::key('promo_discounts')->id)->sum('debit'))->toBe(15000.0)
        ->and(JournalLine::where('ledger_account_id', LedgerAccount::key('partner_payable')->id)->count())->toBe(0);

    $this->actingAs($staff, 'staff')->get(route('staff.promotions.index'))->assertOk()->assertSee('LAUNCH-15')->assertSee('UGX 15,000');
});

test('a promotion that has ended, or is for other plans, gives no discount', function () {
    $partner = approvedPartner(partnerPromo(['ends_on' => today()->subDay()]));
    $landlord = Landlord::factory()->create(['subscription_status' => 'trialing']);
    [$admin, $plan] = partnerPayingLandlord($landlord);
    $landlord->update(['partner_id' => $partner->id, 'promotion_id' => $partner->default_promotion_id]);

    expect((float) partnerPaySubscription($this, $admin, $plan)->amount)->toBe(50000.0);

    $other = partnerPromo(['plan_ids' => [999999]]);
    $landlord->update(['promotion_id' => $other->id]);
    expect(app(\App\Services\PartnerProgram::class)->discountFor($landlord->fresh(), $plan->id, 'monthly', 50000))->toBeNull();
});

test('partner pages need a partner sign-in; staff pages need the partner permissions', function () {
    $this->get(route('partner.dashboard'))->assertRedirect(route('partner.login'));
    $this->get(route('partner.apply'))->assertOk()->assertSee('Become a Primora partner');
    $this->get(route('partner.agreement.public'))->assertOk()->assertSee('partner agreement');

    $nobody = StaffUser::factory()->withPermissions(['landlords.view'])->create();
    $this->actingAs($nobody, 'staff')->get(route('staff.partners.index'))->assertForbidden();
    $this->actingAs($nobody, 'staff')->get(route('staff.promotions.index'))->assertForbidden();

    $viewer = StaffUser::factory()->withPermissions(['partners.view'])->create();
    $this->actingAs($viewer, 'staff')->get(route('staff.partners.index'))->assertOk();
    $this->actingAs($viewer, 'staff')->post(route('staff.promotions.store'), [])->assertForbidden();
});
