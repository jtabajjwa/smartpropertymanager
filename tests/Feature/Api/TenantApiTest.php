<?php

use App\Mail\IssueReportedMail;
use App\Models\CollectionFeeTier;
use App\Models\Estate;
use App\Models\Invoice;
use App\Models\Issue;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\SecurityDepositLedger;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\CurrentLandlord;
use App\Services\SmsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
 * The mobile app's tenant side: sign-in with a code (no session: sealed
 * tickets between steps), several tenancies on one phone, the account,
 * paying by mobile money, the deposit, and issues.
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

/** A landlord with a tenant on $phone in Acacia Court A4 and an unpaid 500,000 invoice. */
function tenantApiTenancy(string $phone = '+256772123456', string $landlordName = 'Kololo Homes', bool $collect = true): array
{
    $landlord = Landlord::factory()->create(['name' => $landlordName, 'collect_payments' => $collect]);
    CurrentLandlord::set($landlord->id);
    $unit = Unit::create(['name' => 'A4', 'estate_id' => Estate::create(['name' => 'Acacia Court', 'district' => 'Kampala'])->id, 'status' => 'occupied']);
    $tenant = tenantApiTenant('Grace Namata', $phone);
    $lease = Lease::create([
        'tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'status' => 'active', 'start_date' => '2026-01-01', 'rent_amount' => 500000,
        'billing_cycle' => 'monthly', 'security_deposit' => 300000, 'required_upfront_periods' => 1, 'next_invoice_date' => today()->toDateString(),
        'number' => 'LSE-'.fake()->unique()->randomNumber(6),
    ]);
    $invoice = Invoice::create([
        'number' => 'INV-'.fake()->unique()->randomNumber(6), 'lease_id' => $lease->id, 'invoiceable_type' => Lease::class, 'invoiceable_id' => $lease->id,
        'type' => 'rent', 'title' => 'November rent', 'invoice_date' => '2026-11-01', 'amount' => 500000, 'due_date' => today()->addDays(5)->toDateString(), 'status' => 'unpaid',
    ]);
    CurrentLandlord::clear();

    return compact('landlord', 'tenant', 'lease', 'invoice', 'unit');
}

function tenantApiTenant(string $name, string $phone): Tenant
{
    return Tenant::create([
        'type' => 'individual', 'name' => $name, 'email' => fake()->unique()->safeEmail(), 'primary_phone' => $phone,
        'id_type' => 'national_id', 'id_number' => 'NIN-'.fake()->unique()->randomNumber(8),
        'emergency_contact_name' => 'Emergency', 'emergency_contact_relationship' => 'family', 'emergency_contact_phone' => '+256700000000',
    ]);
}

function tenantApiCode($test): string
{
    preg_match('/^(\d{6}) is your/', end($test->sentSms)[1], $m);

    return $m[1];
}

/** Sign in by phone; returns the token. */
function tenantApiSignIn($test, string $phone = '0772 123 456'): string
{
    $ticket = $test->postJson(route('api.v1.tenant.auth.lookup'), ['identifier' => $phone])->assertOk()->json('login_ticket');
    $token = $test->postJson(route('api.v1.tenant.auth.verify'), ['login_ticket' => $ticket, 'code' => tenantApiCode($test), 'device_name' => 'Pixel 8'])->assertOk()->json('token');
    CurrentLandlord::clear();
    app('auth')->forgetGuards();

    return $token;
}

test('a tenant signs in with a code by SMS and sees their account', function () {
    ['tenant' => $tenant, 'invoice' => $invoice, 'unit' => $unit] = tenantApiTenancy();
    expect($unit->fresh()->code)->not->toBeEmpty();

    $this->postJson(route('api.v1.tenant.auth.lookup'), ['identifier' => '0700 000 001'])->assertUnprocessable()->assertJsonValidationErrors('identifier');
    $r = $this->postJson(route('api.v1.tenant.auth.lookup'), ['identifier' => '0772 123 456'])->assertOk()
        ->assertJsonPath('channel', 'sms')->assertJsonPath('sent_to', '07•• ••• 456');
    expect($this->sentSms[0][0])->toBe('256772123456');

    $ticket = $r->json('login_ticket');
    $this->postJson(route('api.v1.tenant.auth.verify'), ['login_ticket' => $ticket, 'code' => '000000', 'device_name' => 'Pixel'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson(route('api.v1.tenant.auth.verify'), ['login_ticket' => 'forged', 'code' => tenantApiCode($this), 'device_name' => 'Pixel'])->assertUnprocessable();

    $signIn = $this->postJson(route('api.v1.tenant.auth.verify'), ['login_ticket' => $ticket, 'code' => tenantApiCode($this), 'device_name' => 'Pixel'])->assertOk()
        ->assertJsonPath('role', 'tenant')->assertJsonPath('tenant.name', 'Grace Namata')->assertJsonPath('landlord.name', 'Kololo Homes')
        ->assertJsonPath('can_pay', true)
        ->assertJsonPath('units.0.unit', 'Acacia Court · A4')->assertJsonPath('units.0.code', $unit->fresh()->code)->json();
    CurrentLandlord::clear();
    app('auth')->forgetGuards();

    // A used code can't sign in again.
    $this->postJson(route('api.v1.tenant.auth.verify'), ['login_ticket' => $ticket, 'code' => tenantApiCode($this), 'device_name' => 'Pixel'])->assertUnprocessable();

    $d = $this->withToken($signIn['token'])->getJson(route('api.v1.tenant.dashboard'))->assertOk()
        ->assertJsonPath('outstanding', 500000)->assertJsonPath('next_due.number', $invoice->number)->assertJsonPath('leases.0.unit', 'Acacia Court · A4');
    $this->withToken($signIn['token'])->getJson(route('api.v1.tenant.invoices'))->assertOk()->assertJsonPath('data.0.balance', 500000);
    $this->withToken($signIn['token'])->get(route('api.v1.tenant.invoices.pdf', $invoice->id))->assertOk()->assertHeader('content-type', 'application/pdf');

    $this->withToken($signIn['token'])->postJson(route('api.v1.tenant.auth.logout'))->assertOk();
    expect($tenant->tokens()->count())->toBe(0);
});

test('tenant and landlord tokens only open their own side, and a changed phone signs the tenant out', function () {
    ['tenant' => $tenant] = tenantApiTenancy();
    ['admin' => $admin] = apiLandlord();

    $tenantToken = tenantApiSignIn($this);
    $this->withToken($tenantToken)->getJson(route('api.v1.dashboard'))->assertForbidden()->assertJsonPath('code', 'not_landlord_user');
    app('auth')->forgetGuards();
    $this->flushHeaders();

    $landlordToken = apiToken($this, $admin);
    $this->withToken($landlordToken)->getJson(route('api.v1.tenant.dashboard'))->assertForbidden()->assertJsonPath('code', 'not_tenant');
    app('auth')->forgetGuards();

    // The landlord changes the tenant's phone: the old sign-in no longer counts.
    Tenant::withoutGlobalScopes()->whereKey($tenant->id)->update(['primary_phone' => '+256701555444']);
    $this->withToken($tenantToken)->getJson(route('api.v1.tenant.dashboard'))->assertUnauthorized()->assertJsonPath('code', 'signed_out');
    expect($tenant->tokens()->count())->toBe(0);
});

test('one phone renting from two landlords picks a tenancy, and can switch', function () {
    ['tenant' => $first] = tenantApiTenancy('+256772123456', 'Kololo Homes');
    ['tenant' => $second] = tenantApiTenancy('0772 123 456', 'Ntinda Flats');

    $ticket = $this->postJson(route('api.v1.tenant.auth.lookup'), ['identifier' => '256772123456'])->assertOk()->json('login_ticket');
    $r = $this->postJson(route('api.v1.tenant.auth.verify'), ['login_ticket' => $ticket, 'code' => tenantApiCode($this), 'device_name' => 'Pixel'])->assertOk()
        ->assertJsonMissingPath('token')->assertJsonCount(2, 'accounts');

    $this->postJson(route('api.v1.tenant.auth.select'), ['account_ticket' => $r->json('account_ticket'), 'tenant_id' => 999999, 'device_name' => 'Pixel'])->assertUnprocessable();
    $token = $this->postJson(route('api.v1.tenant.auth.select'), ['account_ticket' => $r->json('account_ticket'), 'tenant_id' => $second->id, 'device_name' => 'Pixel'])
        ->assertOk()->assertJsonPath('landlord.name', 'Ntinda Flats')->assertJsonPath('account_count', 2)->json('token');
    CurrentLandlord::clear();
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson(route('api.v1.tenant.accounts'))->assertOk()->assertJsonPath('current', $second->id)->assertJsonCount(2, 'accounts');
    $new = $this->withToken($token)->postJson(route('api.v1.tenant.accounts.switch'), ['tenant_id' => $first->id])->assertOk()
        ->assertJsonPath('landlord.name', 'Kololo Homes')->json('token');
    CurrentLandlord::clear();
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson(route('api.v1.tenant.dashboard'))->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($new)->getJson(route('api.v1.tenant.auth.me'))->assertOk()->assertJsonPath('tenant.id', $first->id);
});

test('a unit ID shared by two tenants offers masked choices without revealing the numbers', function () {
    ['landlord' => $landlord, 'unit' => $unit, 'lease' => $lease] = tenantApiTenancy();
    CurrentLandlord::set($landlord->id);
    $other = tenantApiTenant('Peter Okello', '0701 999 888');
    Lease::create(['tenant_id' => $other->id, 'unit_id' => $unit->id, 'status' => 'active', 'start_date' => '2026-01-01', 'rent_amount' => 500000, 'billing_cycle' => 'monthly',
        'security_deposit' => 0, 'required_upfront_periods' => 1, 'next_invoice_date' => today()->toDateString(), 'number' => 'LSE-888888']);
    CurrentLandlord::clear();

    $r = $this->postJson(route('api.v1.tenant.auth.lookup'), ['identifier' => $unit->fresh()->code])->assertOk()->assertJsonCount(2, 'choices');
    expect($r->getContent())->not->toContain('701999888')->not->toContain('772123456');

    $this->postJson(route('api.v1.tenant.auth.choose'), ['choice_ticket' => $r->json('choice_ticket'), 'choice' => 1])->assertOk()->assertJsonPath('sent_to', '07•• ••• 888');
    expect(end($this->sentSms)[0])->toBe('256701999888');
});

test('a tenant pays an invoice by mobile money with the fee passed on, and gets a receipt', function () {
    CollectionFeeTier::create(['min_amount' => 500, 'max_amount' => 50000, 'fixed_fee' => 500]);
    CollectionFeeTier::create(['min_amount' => 50001, 'max_amount' => null, 'fixed_fee' => 1000]);
    ['tenant' => $tenant, 'invoice' => $invoice, 'landlord' => $landlord] = tenantApiTenancy();
    $landlord->update(['pass_collection_fee' => true]);
    $token = tenantApiSignIn($this);

    $this->withToken($token)->getJson(route('api.v1.tenant.pay.options'))->assertOk()
        ->assertJsonPath('enabled', true)->assertJsonPath('pass_fee', true)->assertJsonPath('open_invoices.0.id', $invoice->id);
    $quote = $this->withToken($token)->getJson(route('api.v1.tenant.pay.quote', ['amount' => 200000]))->assertOk()->json();
    expect($quote['charge'])->toBeGreaterThan(200000)->and($quote['bearer'])->toBe('tenant');

    $this->withToken($token)->postJson(route('api.v1.tenant.pay.store'), ['invoice_id' => $invoice->id, 'amount' => 600000, 'phone' => '0772123456'])
        ->assertUnprocessable()->assertJsonValidationErrors('amount');
    $pay = $this->withToken($token)->postJson(route('api.v1.tenant.pay.store'), ['invoice_id' => $invoice->id, 'amount' => 200000, 'phone' => '0772123456'])->assertCreated()->json();
    $this->withToken($token)->postJson(route('api.v1.tenant.pay.store'), ['amount' => 1000, 'phone' => '0772123456'])
        ->assertUnprocessable()->assertJsonPath('errors.phone.0', fn ($m) => str_contains($m, 'already waiting'));

    $this->travel(6)->seconds();
    $this->withToken($token)->getJson(route('api.v1.tenant.pay.status', $pay['reference']))->assertOk()
        ->assertJsonPath('status', PaymentTransaction::SUCCEEDED)->assertJsonPath('principal', 200000);

    $payments = $this->withToken($token)->getJson(route('api.v1.tenant.payments'))->assertOk()
        ->assertJsonPath('data.0.amount', 200000)->assertJsonPath('data.0.for', $invoice->number)->json();
    $this->withToken($token)->get(route('api.v1.tenant.payments.receipt', $payments['data'][0]['id']))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->withToken($token)->getJson(route('api.v1.tenant.invoices', ['status' => 'open']))->assertOk()->assertJsonPath('data.0.balance', 300000);

    // Someone else's payment isn't reachable.
    expect(Payment::withoutGlobalScopes()->count())->toBe(1);
    $this->withToken($token)->get(route('api.v1.tenant.payments.receipt', 999999))->assertNotFound();
});

test('the deposit shows what was paid and used, with the balance after each entry', function () {
    ['landlord' => $landlord, 'lease' => $lease] = tenantApiTenancy();
    CurrentLandlord::set($landlord->id);
    SecurityDepositLedger::create(['date' => '2026-01-01', 'lease_id' => $lease->id, 'type' => 'deposit', 'amount' => 300000]);
    SecurityDepositLedger::create(['date' => '2026-06-01', 'lease_id' => $lease->id, 'type' => 'deduction', 'category' => array_key_first(SecurityDepositLedger::CATEGORIES), 'amount' => 50000, 'notes' => 'Broken window']);
    CurrentLandlord::clear();
    $token = tenantApiSignIn($this);

    $this->withToken($token)->getJson(route('api.v1.tenant.deposit'))->assertOk()
        ->assertJsonPath('deposits.0.paid', 300000)->assertJsonPath('deposits.0.used', 50000)->assertJsonPath('deposits.0.balance', 250000)
        ->assertJsonPath('deposits.0.entries.1.notes', 'Broken window')->assertJsonPath('deposits.0.entries.1.balance', 250000);
});

test('a tenant reports an issue with a photo, follows it up, and cannot see other tenants\' issues', function () {
    Mail::fake();
    Storage::fake('public');
    ['landlord' => $landlord, 'lease' => $lease] = tenantApiTenancy();
    $token = tenantApiSignIn($this);

    $this->withToken($token)->getJson(route('api.v1.tenant.issues.options'))->assertOk()->assertJsonCount(3, 'categories')->assertJsonPath('leases.0.id', $lease->id);
    $this->withToken($token)->postJson(route('api.v1.tenant.issues.store'), ['category' => 'repair', 'description' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('title');

    $id = $this->withToken($token)->post(route('api.v1.tenant.issues.store'), [
        'category' => 'repair', 'title' => 'Leaking pipe', 'description' => 'Under the kitchen sink.', 'photo' => UploadedFile::fake()->image('pipe.jpg'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('id');
    Mail::assertSent(IssueReportedMail::class);

    $this->withToken($token)->getJson(route('api.v1.tenant.issues.show', $id))->assertOk()
        ->assertJsonPath('title', 'Leaking pipe')->assertJsonPath('unit', 'Acacia Court · A4')->assertJsonPath('updates.0.by', 'You')
        ->assertJsonPath('photo_url', fn ($u) => $u !== null);

    Issue::withoutGlobalScopes()->whereKey($id)->update(['status' => 'resolved', 'resolved_at' => now()]);
    $this->withToken($token)->postJson(route('api.v1.tenant.issues.comment', $id), ['note' => 'Still leaking'])->assertOk()
        ->assertJsonPath('message', fn ($m) => str_contains($m, 're-opened'));
    expect(Issue::withoutGlobalScopes()->find($id)->status)->toBe('open');
    $this->withToken($token)->getJson(route('api.v1.tenant.issues.index'))->assertOk()->assertJsonPath('data.0.id', $id);

    // Another tenant of the same landlord.
    CurrentLandlord::set($landlord->id);
    $peter = tenantApiTenant('Peter Okello', '0701 999 888');
    $theirs = Issue::create(['tenant_id' => $peter->id, 'category' => 'request', 'title' => 'Keys', 'description' => 'Spare key', 'status' => 'open', 'number' => 'ISS-9']);
    CurrentLandlord::clear();
    $this->withToken($token)->getJson(route('api.v1.tenant.issues.show', $theirs->id))->assertNotFound();
    $this->withToken($token)->postJson(route('api.v1.tenant.issues.comment', $theirs->id), ['note' => 'Hi'])->assertNotFound();
});
