<?php

use App\Http\Middleware\AuthenticateTenant;
use App\Mail\IssueReportedMail;
use App\Models\CollectionFeeTier;
use App\Models\Estate;
use App\Models\Invoice;
use App\Models\Issue;
use App\Models\Landlord;
use App\Models\LandlordSettlement;
use App\Models\Lease;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\StaffUser;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\CollectionFees;
use App\Services\CurrentLandlord;
use App\Services\SmsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
 * Tenant portal: SMS-code sign-in, dashboard, paying by mobile money into
 * the Primora pool (with the tiered collection fee), issues, agreements;
 * and the staff side of rent collections.
 */

beforeEach(function () {
    config(['payments.default' => 'fake', 'payments.gateways.fake.succeed_after_seconds' => 0]);
    $this->sentSms = [];
    $this->mock(SmsService::class, function ($mock) {
        $mock->shouldReceive('send')->andReturnUsing(function ($phone, $message) {
            $this->sentSms[] = [$phone, $message];

            return true;
        });
    });
});
afterEach(fn () => CurrentLandlord::clear());

/** A landlord with one tenant in unit $unitName, an active lease and one unpaid 500,000 rent invoice. */
function tenancy(string $phone = '+256772123456', string $unitName = 'A4', bool $collect = true, string $landlordName = 'Kololo Homes'): array
{
    $landlord = Landlord::factory()->create(['name' => $landlordName, 'contact_email' => 'owner@'.Str::slug($landlordName).'.test', 'collect_payments' => $collect]);
    CurrentLandlord::set($landlord->id);

    $location = Location::create(['name' => 'Kololo', 'country' => 'Uganda', 'district' => 'Kampala', 'address' => 'Plot 1']);
    $estate = Estate::create(['name' => 'Acacia Court', 'location_id' => $location->id]);
    $unit = Unit::create(['name' => $unitName, 'estate_id' => $estate->id, 'status' => 'occupied']);
    $tenant = Tenant::create([
        'type' => 'individual', 'name' => 'Grace Namata', 'email' => fake()->unique()->safeEmail(), 'primary_phone' => $phone,
        'id_type' => 'national_id', 'id_number' => 'NIN-'.fake()->unique()->randomNumber(8),
        'emergency_contact_name' => 'Emergency', 'emergency_contact_relationship' => 'family', 'emergency_contact_phone' => '+256700000000',
    ]);
    $lease = Lease::create([
        'tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'status' => 'active', 'start_date' => '2026-01-01', 'rent_amount' => 500000,
        'billing_cycle' => 'monthly', 'security_deposit' => 0, 'required_upfront_periods' => 1, 'next_invoice_date' => today()->toDateString(),
        'number' => 'LSE-'.fake()->unique()->randomNumber(6),
    ]);
    $invoice = Invoice::create([
        'number' => 'INV-'.fake()->unique()->randomNumber(6), 'lease_id' => $lease->id, 'invoiceable_type' => Lease::class, 'invoiceable_id' => $lease->id,
        'type' => 'rent', 'title' => 'October rent', 'invoice_date' => '2026-10-01', 'amount' => 500000, 'due_date' => today()->addDays(5)->toDateString(), 'status' => 'unpaid',
    ]);
    CurrentLandlord::clear();

    return compact('landlord', 'tenant', 'lease', 'invoice', 'unit');
}

function asTenant($test, Tenant $tenant, string $msisdn = '256772123456')
{
    return $test->withSession([AuthenticateTenant::SESSION => ['msisdn' => $msisdn, 'tenant_ids' => [$tenant->id], 'tenant_id' => $tenant->id]]);
}

function lastCode($test): string
{
    preg_match('/^(\d{6}) is your/', end($test->sentSms)[1], $m);

    return $m[1];
}

function feeBands(): void
{
    CollectionFeeTier::create(['min_amount' => 500, 'max_amount' => 50000, 'fixed_fee' => 500]);
    CollectionFeeTier::create(['min_amount' => 50001, 'max_amount' => null, 'fixed_fee' => 1000]);
}

// --- Sign in -------------------------------------------------------------

test('a tenant signs in with their phone number and an SMS code', function () {
    ['tenant' => $tenant] = tenancy();

    $this->post(route('tenant.login'), ['identifier' => '0772 123 456'])->assertRedirect(route('tenant.verify'));
    expect($this->sentSms)->toHaveCount(1)->and($this->sentSms[0][0])->toBe('256772123456');

    $this->post(route('tenant.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
    $this->post(route('tenant.verify'), ['code' => lastCode($this)])->assertRedirect(route('tenant.dashboard'));

    $this->get(route('tenant.dashboard'))->assertOk()
        ->assertSee('Grace')->assertSee('UGX 500,000')->assertSee('October rent')->assertSee('Kololo Homes');
});

test('a tenant can sign in with their unit number, choosing their phone if the unit number is shared', function () {
    tenancy('+256772123456', 'A4', true, 'Kololo Homes');
    tenancy('0701 999 888', 'a4 ', true, 'Ntinda Flats');

    $this->post(route('tenant.login'), ['identifier' => 'A4'])->assertRedirect(route('tenant.login.choose'));
    $this->get(route('tenant.login.choose'))->assertOk()->assertSee('07•• ••• 456', false)->assertSee('07•• ••• 888', false);

    $this->post(route('tenant.login.choose'), ['choice' => 1])->assertRedirect(route('tenant.verify'));
    expect(end($this->sentSms)[0])->toBe('256701999888');
});

test('unknown numbers, ended landlords and wrong codes get nowhere', function () {
    ['landlord' => $landlord] = tenancy();

    $this->post(route('tenant.login'), ['identifier' => '0700 000 001'])->assertSessionHasErrors('identifier');
    $landlord->update(['status' => 'suspended']);
    $this->post(route('tenant.login'), ['identifier' => '0772123456'])->assertSessionHasErrors('identifier');
    expect($this->sentSms)->toBe([]);
});

test('a code stops working after five wrong tries, and codes cannot be requested back to back', function () {
    tenancy();
    $this->post(route('tenant.login'), ['identifier' => '0772123456']);
    $code = lastCode($this);

    for ($i = 0; $i < 5; $i++) {
        $this->post(route('tenant.verify'), ['code' => '111111']);
    }
    $this->post(route('tenant.verify'), ['code' => $code])->assertSessionHasErrors('code');

    $this->post(route('tenant.verify.resend'))->assertSessionHas('error');
    expect($this->sentSms)->toHaveCount(1);
});

test('one phone on two tenancies picks which to open', function () {
    ['tenant' => $a] = tenancy('+256772123456', 'A4', true, 'Kololo Homes');
    ['tenant' => $b] = tenancy('0772 123 456', 'B1', true, 'Ntinda Flats');

    $this->post(route('tenant.login'), ['identifier' => '0772123456']);
    $this->post(route('tenant.verify'), ['code' => lastCode($this)])->assertRedirect(route('tenant.accounts'));
    $this->get(route('tenant.accounts'))->assertSee('Kololo Homes')->assertSee('Ntinda Flats');

    $this->post(route('tenant.accounts'), ['tenant_id' => $b->id])->assertRedirect(route('tenant.dashboard'));
    $this->get(route('tenant.dashboard'))->assertSee('Ntinda Flats');
});

test('the portal needs a signed-in tenant, and a changed phone signs them out', function () {
    ['tenant' => $tenant] = tenancy();

    $this->get(route('tenant.dashboard'))->assertRedirect(route('tenant.login'));

    Tenant::withoutGlobalScopes()->whereKey($tenant->id)->update(['primary_phone' => '+256700111222']);
    asTenant($this, $tenant)->get(route('tenant.dashboard'))->assertRedirect(route('tenant.login'));
});

test('tenants only ever see their own documents and issues', function () {
    ['tenant' => $mine] = tenancy();
    ['invoice' => $theirs, 'lease' => $theirLease, 'tenant' => $other, 'landlord' => $otherLandlord] = tenancy('+256701555444', 'C3', true, 'Other Estates');
    CurrentLandlord::set($otherLandlord->id);
    $issue = Issue::create(['tenant_id' => $other->id, 'category' => 'repair', 'title' => 'Not yours', 'description' => 'x', 'status' => 'open']);
    CurrentLandlord::clear();

    asTenant($this, $mine)->get(route('tenant.invoices.pdf', $theirs->id))->assertNotFound();
    asTenant($this, $mine)->get(route('tenant.leases.agreement', $theirLease->id))->assertNotFound();
    asTenant($this, $mine)->get(route('tenant.issues.show', $issue->id))->assertNotFound();
});

test('a tenant views their invoice and tenancy agreement', function () {
    ['tenant' => $tenant, 'invoice' => $invoice, 'lease' => $lease, 'landlord' => $landlord] = tenancy();
    app(\App\Services\StandardLeaseTemplate::class)->installFor($landlord->id);

    asTenant($this, $tenant)->get(route('tenant.invoices.pdf', $invoice->id))->assertOk()->assertHeader('content-type', 'application/pdf');
    asTenant($this, $tenant)->get(route('tenant.leases.agreement', $lease->id))->assertOk()->assertHeader('content-type', 'application/pdf');
});

// --- Paying ----------------------------------------------------------------

test('collection fees follow the bands; the landlord bears them unless passed to tenants', function () {
    feeBands();
    $landlord = Landlord::factory()->create(['pass_collection_fee' => false]);
    $fees = app(CollectionFees::class);

    expect($fees->quote($landlord, 500000))->toMatchArray(['charge' => 500000.0, 'fee' => 1000.0, 'landlord_amount' => 499000.0, 'bearer' => 'landlord']);

    $landlord->pass_collection_fee = true;
    // 49,800 + 500 = 50,300 falls in the next band, so the fee becomes 1,000.
    expect(app(CollectionFees::class)->quote($landlord, 49800))->toMatchArray(['charge' => 50800.0, 'fee' => 1000.0, 'landlord_amount' => 49800.0, 'bearer' => 'tenant'])
        ->and(app(CollectionFees::class)->quote($landlord, 20000))->toMatchArray(['charge' => 20500.0, 'fee' => 500.0]);
});

test('paying an invoice by mobile money records the payment against it once approved', function () {
    feeBands();
    ['tenant' => $tenant, 'invoice' => $invoice, 'landlord' => $landlord] = tenancy();
    app(\App\Services\SmsCredits::class)->add($landlord->id, 10, 'purchase');

    asTenant($this, $tenant)->post(route('tenant.pay'), ['invoice_id' => $invoice->id, 'amount' => 500000, 'phone' => '0772 123 456'])
        ->assertRedirect(route('tenant.dashboard'));

    $tx = PaymentTransaction::sole();
    expect($tx->purpose)->toBe('rent')->and((float) $tx->amount)->toBe(500000.0)->and((float) $tx->fee_amount)->toBe(1000.0)
        ->and($tx->fee_bearer)->toBe('landlord')->and((float) $tx->landlord_amount)->toBe(499000.0)
        ->and($tx->reference)->toStartWith('RNT-')->and($tx->narrative)->toContain('PRIMORA:RNT')->toContain($invoice->number)
        ->and(Payment::withoutGlobalScopes()->count())->toBe(0);

    PaymentTransaction::whereKey($tx->id)->update(['last_checked_at' => now()->subMinute()]);
    asTenant($this, $tenant)->getJson(route('tenant.pay.status', $tx->reference))->assertOk()->assertJson(['status' => 'succeeded']);

    $payment = Payment::withoutGlobalScopes()->sole();
    expect($payment->landlord_id)->toBe($landlord->id)->and((float) $payment->amount)->toBe(500000.0)
        ->and($payment->payable_id)->toBe($tenant->id)->and($payment->payment_method)->toBe('mtn_mobile_money')
        ->and($payment->status)->toBe('fully_allocated')->and($payment->number)->toStartWith('PAY')
        ->and(Invoice::withoutGlobalScopes()->find($invoice->id)->status)->toBe('paid')
        ->and($tx->fresh()->context['payment_id'])->toBe($payment->id);

    // Applied exactly once.
    $this->artisan('payments:reconcile');
    expect(Payment::withoutGlobalScopes()->count())->toBe(1);

    // Receipt SMS (from the landlord's credit).
    expect(collect($this->sentSms)->contains(fn ($s) => str_contains($s[1], 'payment of UGX 500,000 received')))->toBeTrue();
});

test('a tenant can pay part of an invoice, or in advance, but not more than is due on it', function () {
    ['tenant' => $tenant, 'invoice' => $invoice] = tenancy();

    asTenant($this, $tenant)->post(route('tenant.pay'), ['invoice_id' => $invoice->id, 'amount' => 600000, 'phone' => '0772123456'])
        ->assertSessionHasErrors('amount');

    asTenant($this, $tenant)->post(route('tenant.pay'), ['amount' => 150000, 'phone' => '0772123456'])->assertRedirect();
    $tx = PaymentTransaction::sole();
    expect($tx->context['invoice_id'])->toBeNull();

    app(\App\Payments\PaymentService::class)->refresh($tx);
    $payment = Payment::withoutGlobalScopes()->sole();
    expect($payment->status)->toBe('unallocated')
        ->and(Invoice::withoutGlobalScopes()->find($invoice->id)->status)->toBe('unpaid');

    asTenant($this, $tenant)->get(route('tenant.dashboard'))->assertSee('UGX 150,000 paid in advance');
});

test('when the landlord passes the fee on, the tenant pays it on top and the landlord gets the full amount', function () {
    feeBands();
    ['tenant' => $tenant, 'invoice' => $invoice, 'landlord' => $landlord] = tenancy();
    $landlord->update(['pass_collection_fee' => true]);

    asTenant($this, $tenant)->post(route('tenant.pay'), ['invoice_id' => $invoice->id, 'amount' => 200000, 'phone' => '0772123456']);

    $tx = PaymentTransaction::sole();
    expect((float) $tx->amount)->toBe(201000.0)->and((float) $tx->landlord_amount)->toBe(200000.0)->and($tx->fee_bearer)->toBe('tenant');

    app(\App\Payments\PaymentService::class)->refresh($tx);
    expect((float) Payment::withoutGlobalScopes()->sole()->amount)->toBe(200000.0)
        ->and(Invoice::withoutGlobalScopes()->find($invoice->id)->status)->toBe('partial');
});

test('tenants of landlords Primora does not collect for cannot pay by mobile money', function () {
    ['tenant' => $tenant, 'invoice' => $invoice] = tenancy(collect: false);

    asTenant($this, $tenant)->get(route('tenant.pay'))->assertOk()->assertSee("aren't available for your tenancy", false);
    asTenant($this, $tenant)->post(route('tenant.pay'), ['invoice_id' => $invoice->id, 'amount' => 1000, 'phone' => '0772123456'])->assertSessionHas('error');
    expect(PaymentTransaction::count())->toBe(0);
});

// --- Issues ----------------------------------------------------------------

test('a tenant reports an issue with a photo; the landlord is emailed and their updates show to the tenant', function () {
    Storage::fake('public');
    Mail::fake();
    ['tenant' => $tenant, 'landlord' => $landlord, 'unit' => $unit] = tenancy();

    asTenant($this, $tenant)->post(route('tenant.issues.store'), [
        'category' => 'repair', 'title' => 'Leaking kitchen pipe', 'description' => 'Water is leaking under the sink since this morning.',
        'photo' => UploadedFile::fake()->image('leak.jpg', 800, 600),
    ])->assertRedirect();

    $issue = Issue::withoutGlobalScopes()->sole();
    expect($issue->landlord_id)->toBe($landlord->id)->and($issue->unit_id)->toBe($unit->id)->and($issue->status)->toBe('open')
        ->and($issue->number)->toStartWith('ISS')->and($issue->photo)->not->toBeNull();
    Storage::disk('public')->assertExists($issue->photo);
    Mail::assertSent(IssueReportedMail::class, fn ($m) => $m->hasTo($landlord->contact_email) && str_contains($m->envelope()->subject, 'Leaking kitchen pipe'));

    // The landlord responds.
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);
    $this->actingAs($admin)->get(route('issues.index'))->assertOk()->assertSee('Leaking kitchen pipe');
    $this->actingAs($admin)->put(route('issues.update', $issue), ['status' => 'resolved', 'note' => 'Plumber fixed it.'])->assertSessionHas('success');
    $this->post(route('logout'));

    asTenant($this, $tenant)->get(route('tenant.issues.show', $issue->id))->assertOk()->assertSee('Resolved')->assertSee('Plumber fixed it.');

    // Still leaking: the tenant's note re-opens it.
    asTenant($this, $tenant)->post(route('tenant.issues.comment', $issue->id), ['note' => 'Still leaking.'])->assertSessionHas('success');
    expect($issue->fresh()->status)->toBe('open');
});

// --- Landlord & staff ------------------------------------------------------

test('only landlords Primora collects for can choose who pays the fee', function () {
    ['landlord' => $landlord] = tenancy(collect: false);
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);

    $this->actingAs($admin)->put(route('company-profile.collections'), ['pass_collection_fee' => 1])->assertForbidden();

    $landlord->update(['collect_payments' => true]);
    $this->actingAs($admin)->put(route('company-profile.collections'), ['pass_collection_fee' => 1])->assertSessionHas('success');
    expect($landlord->fresh()->pass_collection_fee)->toBeTrue();
});

test('staff can switch tenant payments on and off from the landlord page', function () {
    ['landlord' => $landlord] = tenancy(collect: false);
    $manager = StaffUser::factory()->withPermissions(['properties.view', 'collections.manage'])->create();
    $viewer = StaffUser::factory()->withPermissions(['properties.view', 'collections.view'])->create();

    $this->actingAs($manager, 'staff')->get(route('staff.landlords.show', $landlord))
        ->assertOk()->assertSee('Tenant mobile money payments')->assertSee('Switch on');
    $this->actingAs($viewer, 'staff')->get(route('staff.landlords.show', $landlord))
        ->assertOk()->assertSee('Tenant mobile money payments')->assertDontSee('Switch on');

    $this->actingAs($manager, 'staff')->from(route('staff.landlords.show', $landlord))
        ->post(route('staff.collections.toggle', $landlord))->assertRedirect(route('staff.landlords.show', $landlord));
    expect($landlord->fresh()->collect_payments)->toBeTrue();
    $this->actingAs($manager, 'staff')->get(route('staff.landlords.show', $landlord))->assertSee('Switch off');

    // Rent Collections lists every landlord by default, so the switch is always reachable there too.
    $other = Landlord::factory()->create(['name' => 'Not Yet Collecting Ltd']);
    $this->actingAs($manager, 'staff')->get(route('staff.collections.index'))->assertOk()->assertSee('Not Yet Collecting Ltd');
    $this->actingAs($manager, 'staff')->get(route('staff.collections.index', ['show' => 'enabled']))->assertDontSee('Not Yet Collecting Ltd');
});

test('staff switch collections on, set fee bands, and settle what landlords are owed', function () {
    $staff = StaffUser::factory()->withPermissions(['collections.manage', 'collections.settle'])->create();
    ['landlord' => $landlord] = tenancy(collect: false);

    $this->actingAs($staff, 'staff')->post(route('staff.collections.toggle', $landlord))->assertSessionHas('success');
    expect($landlord->fresh()->collect_payments)->toBeTrue();

    $this->actingAs($staff, 'staff')->post(route('staff.collections.tiers.store'), ['min_amount' => 500, 'max_amount' => 50000, 'fixed_fee' => 500])->assertSessionHas('success');
    $this->actingAs($staff, 'staff')->post(route('staff.collections.tiers.store'), ['min_amount' => 40000, 'max_amount' => 90000, 'fixed_fee' => 800])->assertSessionHasErrors('min_amount');

    $collected = PaymentTransaction::create([
        'reference' => 'RNT-261027-AAAAAA', 'purpose' => 'rent', 'landlord_id' => $landlord->id, 'gateway' => 'fake', 'amount' => 300000,
        'fee_amount' => 1000, 'fee_bearer' => 'landlord', 'landlord_amount' => 299000, 'msisdn' => '256772123456', 'narrative' => 'x', 'status' => 'succeeded',
    ]);
    app(\App\Accounting\Postings::class)->paymentSucceeded($collected); // as the payment flow does
    $this->actingAs($staff, 'staff')->post(route('staff.collections.settlements.store', $landlord), [
        'amount' => 200000, 'method' => 'bank_transfer', 'settled_on' => today()->toDateString(), 'reference' => 'TT-55',
    ])->assertSessionHas('success');

    expect(LandlordSettlement::sole()->recorded_by_staff_user_id)->toBe($staff->id);
    $this->actingAs($staff, 'staff')->get(route('staff.collections.show', $landlord))->assertOk()->assertSee('UGX 99,000')->assertSee('TT-55');
    $this->actingAs($staff, 'staff')->get(route('staff.collections.index'))->assertOk()->assertSee($landlord->name);
});

test('tenants renting several units see which unit each invoice is for', function () {
    ['tenant' => $tenant, 'landlord' => $landlord, 'unit' => $a4] = tenancy();
    ['tenant' => $single] = tenancy('+256701222333', 'C9', true, 'Other Homes');

    // A second unit for the same tenant, with its own rent invoice.
    CurrentLandlord::set($landlord->id);
    $b2 = Unit::create(['name' => 'B2', 'estate_id' => $a4->estate_id, 'status' => 'occupied']);
    $lease = Lease::create([
        'tenant_id' => $tenant->id, 'unit_id' => $b2->id, 'status' => 'active', 'start_date' => '2026-01-01', 'rent_amount' => 400000,
        'billing_cycle' => 'monthly', 'security_deposit' => 0, 'required_upfront_periods' => 1, 'next_invoice_date' => today()->toDateString(), 'number' => 'LSE-B2',
    ]);
    $invoice = Invoice::create([
        'number' => 'INV-B2', 'lease_id' => $lease->id, 'invoiceable_type' => Lease::class, 'invoiceable_id' => $lease->id,
        'type' => 'rent', 'title' => 'October rent', 'invoice_date' => '2026-10-01', 'amount' => 400000, 'due_date' => today()->addDays(5)->toDateString(), 'status' => 'unpaid',
    ]);
    CurrentLandlord::clear();

    foreach (['tenant.dashboard', 'tenant.invoices', 'tenant.pay'] as $route) {
        asTenant($this, $tenant)->get(route($route))->assertOk()
            ->assertSee('Unit A4 · Acacia Court')->assertSee('Unit B2 · Acacia Court');
    }

    // One unit: no tags (it would only be noise).
    asTenant($this, $single, '256701222333')->get(route('tenant.invoices'))->assertOk()->assertDontSee('Unit C9 · Acacia Court');
});
