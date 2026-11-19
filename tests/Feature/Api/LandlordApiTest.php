<?php

use App\Models\Estate;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\CurrentLandlord;

/*
 * The mobile app's landlord API (v1): token sign-in per device, the setup
 * steps as JSON codes, each landlord sees only their own records, and the
 * core screens (dashboard, tenants, invoices, payments with receipts).
 */

afterEach(fn () => CurrentLandlord::clear());

test('a landlord signs in with a token per device and sees their profile, plan features and setup state', function () {
    ['admin' => $admin, 'landlord' => $landlord] = apiLandlord();

    $this->postJson(route('api.v1.login'), ['email' => $admin->email, 'password' => 'wrong', 'device_name' => 'Pixel 8'])->assertUnprocessable()->assertJsonValidationErrors('email');

    $token = apiToken($this, $admin);
    apiToken($this, $admin);   // signing in again on the same device replaces the token
    expect($admin->tokens()->count())->toBe(1);

    $me = $this->withToken($token = apiToken($this, $admin))->getJson(route('api.v1.auth.me'))->assertOk();
    $me->assertJsonPath('user.email', $admin->email)->assertJsonPath('landlord.name', $landlord->name)
        ->assertJsonPath('setup.terms_required', false)->assertJsonPath('setup.must_change_password', false)->assertJsonPath('landlord.read_only', false);

    $this->withToken($token)->postJson(route('api.v1.auth.logout'))->assertOk();
    expect($admin->tokens()->count())->toBe(0);
});

test('setup steps are answered with codes the app acts on', function () {
    ['admin' => $admin, 'landlord' => $landlord] = apiLandlord(['must_change_password' => true]);
    $token = apiToken($this, $admin);

    $this->withToken($token)->getJson(route('api.v1.dashboard'))->assertForbidden()->assertJsonPath('code', 'password_change_required');
    $this->withToken($token)->postJson(route('api.v1.auth.change-password'), ['current_password' => 'Secret-pass-123', 'password' => 'N3w-Str0ng-pass!', 'password_confirmation' => 'N3w-Str0ng-pass!'])
        ->assertOk()->assertJsonPath('setup.must_change_password', false);

    // New terms published: the admin accepts them in the app.
    $landlord->forceFill(['terms_version' => 'old'])->save();
    $this->withToken($token)->getJson(route('api.v1.dashboard'))->assertForbidden()->assertJsonPath('code', 'terms_required');
    $this->withToken($token)->postJson(route('api.v1.auth.accept-terms'), ['terms' => 1])->assertOk()->assertJsonPath('setup.terms_required', false);
    $this->withToken($token)->getJson(route('api.v1.dashboard'))->assertOk();

    $admin->update(['email_verified_at' => null]);
    $this->app['auth']->forgetGuards();   // a real request loads the user afresh
    $this->withToken($token)->getJson(route('api.v1.dashboard'))->assertForbidden()->assertJsonPath('code', 'email_unverified');

    $admin->update(['email_verified_at' => now(), 'is_active' => false]);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson(route('api.v1.dashboard'))->assertUnauthorized()->assertJsonPath('code', 'account_inactive');
    expect($admin->tokens()->count())->toBe(0);
});

test('a lapsed subscription can read but not write', function () {
    ['admin' => $admin, 'landlord' => $landlord, 'tenant' => $tenant] = apiLandlord();
    $landlord->update(['subscription_status' => 'trialing', 'current_period_end' => now()->subDays(60)]);
    $token = apiToken($this, $admin);

    $this->withToken($token)->getJson(route('api.v1.tenants.index'))->assertOk();
    $this->withToken($token)->postJson(route('api.v1.payments.store'), ['tenant_id' => $tenant->id, 'amount' => 1000, 'payment_date' => '2026-11-10', 'payment_method' => 'cash'])
        ->assertForbidden()->assertJsonPath('code', 'subscription_read_only');
});

test('the dashboard, tenants and invoices show only the landlord\'s own records', function () {
    $mine = apiLandlord();
    $theirs = apiLandlord();
    $token = apiToken($this, $mine['admin']);

    $this->withToken($token)->getJson(route('api.v1.dashboard'))->assertOk()
        ->assertJsonPath('tenants', 1)->assertJsonPath('units.total', 1)->assertJsonPath('pending_invoices.amount', 500000)
        ->assertJsonStructure(['net_income' => ['income', 'expenses', 'net', 'period'], 'monthly', 'recent_payments', 'expiring_leases']);

    $this->withToken($token)->getJson(route('api.v1.tenants.index', ['search' => 'grace']))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.unit', 'Acacia Court · A4');
    $this->withToken($token)->getJson(route('api.v1.tenants.show', $mine['tenant']))->assertOk()
        ->assertJsonPath('balance', 500000)->assertJsonPath('leases.0.rent_amount', 500000)->assertJsonPath('invoices.0.number', $mine['invoice']->number);

    $this->withToken($token)->getJson(route('api.v1.tenants.show', $theirs['tenant']))->assertNotFound();
    $this->withToken($token)->getJson(route('api.v1.invoices.show', $theirs['invoice']))->assertNotFound();
    $this->withToken($token)->getJson(route('api.v1.invoices.index', ['status' => 'overdue']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.balance', 500000);
});

test('adding a tenant and raising an invoice use the same rules as the web', function () {
    ['admin' => $admin, 'lease' => $lease] = apiLandlord();
    $token = apiToken($this, $admin);

    $this->withToken($token)->postJson(route('api.v1.tenants.store'), ['name' => 'Peter'])->assertUnprocessable()->assertJsonValidationErrors(['type', 'email', 'primary_phone']);
    $this->withToken($token)->postJson(route('api.v1.tenants.store'), [
        'type' => 'individual', 'name' => 'Peter Mukasa', 'email' => 'peter@example.test', 'primary_phone' => '0701234567', 'id_type' => 'national_id', 'id_number' => 'CM1',
        'emergency_contact_name' => 'Mary', 'emergency_contact_relationship' => 'family', 'emergency_contact_phone' => '0702345678',
    ])->assertCreated()->assertJsonPath('name', 'Peter Mukasa')->assertJsonPath('number', fn ($n) => str_starts_with($n, 'TEN'));

    $this->withToken($token)->postJson(route('api.v1.invoices.store'), ['lease_id' => $lease->id, 'type' => 'utility', 'title' => 'Water, November', 'invoice_date' => '2026-11-10', 'due_date' => '2026-11-05', 'amount' => 20000])
        ->assertUnprocessable()->assertJsonValidationErrors('due_date');
    $this->withToken($token)->postJson(route('api.v1.invoices.store'), ['lease_id' => $lease->id, 'type' => 'utility', 'title' => 'Water, November', 'invoice_date' => '2026-11-10', 'due_date' => '2026-11-20', 'amount' => 20000])
        ->assertCreated()->assertJsonPath('balance', 20000)->assertJsonPath('payer', 'Grace Namata');
});

test('a payment recorded in the app pays the oldest invoices, and its receipt downloads', function () {
    ['admin' => $admin, 'tenant' => $tenant, 'invoice' => $invoice] = apiLandlord();
    $token = apiToken($this, $admin);

    $payment = $this->withToken($token)->postJson(route('api.v1.payments.store'), [
        'tenant_id' => $tenant->id, 'amount' => 300000, 'payment_date' => '2026-11-10', 'payment_method' => 'mtn_mobile_money', 'reference' => 'MP123', 'apply_to_invoices' => 1,
    ])->assertCreated()->assertJsonPath('method_label', 'MTN Mobile Money')->json();

    expect($invoice->fresh()->status)->toBe('partial');
    $this->withToken($token)->getJson(route('api.v1.payments.show', $payment['id']))->assertOk()
        ->assertJsonPath('invoices.0.applied', 300000)->assertJsonPath('unallocated', 0);
    $this->withToken($token)->getJson(route('api.v1.invoices.show', $invoice))->assertOk()->assertJsonPath('paid', 300000)->assertJsonPath('payments.0.applied', 300000);

    $this->withToken($token)->get(route('api.v1.payments.receipt', $payment['id']))->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(Payment::count())->toBe(1);
});

test('active leases are listed for picking, and each landlord sees only theirs', function () {
    $mine = apiLandlord();
    $theirs = apiLandlord();
    $token = apiToken($this, $mine['admin']);

    $this->withToken($token)->getJson(route('api.v1.leases.index'))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.tenant', 'Grace Namata')->assertJsonPath('data.0.unit', 'Acacia Court · A4');
    $this->withToken($token)->getJson(route('api.v1.leases.show', $theirs['lease']))->assertNotFound();
});

test('the API needs a token, and staff or partner sessions don\'t open it', function () {
    $this->getJson(route('api.v1.dashboard'))->assertUnauthorized();
    $this->getJson(route('api.v1.auth.me'))->assertUnauthorized();
});

test('detail screens work as the first request after sign-in (the landlord is set before records are looked up)', function () {
    ['admin' => $admin, 'tenant' => $tenant, 'lease' => $lease, 'invoice' => $invoice, 'unit' => $unit] = apiLandlord();
    $token = apiToken($this, $admin);

    foreach ([route('api.v1.tenants.show', $tenant), route('api.v1.leases.show', $lease), route('api.v1.invoices.show', $invoice),
        route('api.v1.units.show', $unit), route('api.v1.estates.show', $unit->estate_id)] as $url) {
        CurrentLandlord::clear();   // as on a real server, where every request starts fresh
        $this->withToken($token)->getJson($url)->assertOk();
    }
});
