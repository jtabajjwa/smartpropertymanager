<?php

use App\Models\Audit;
use App\Models\Estate;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\StaffUser;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\CurrentLandlord;
use App\Services\StaffActingSession;

afterEach(fn () => CurrentLandlord::clear());

/** A landlord with an admin user, one vacant unit and one tenant. */
function portalLandlord(string $name = 'Acme Properties'): array
{
    $landlord = Landlord::factory()->create(['name' => $name]);
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);

    CurrentLandlord::set($landlord->id);
    $estate = Estate::create(['name' => "{$name} Courts", 'district' => 'Kampala']);
    $unit = Unit::create(['name' => 'A1', 'estate_id' => $estate->id, 'status' => 'vacant']);
    $tenant = Tenant::create([
        'type' => 'individual',
        'name' => "{$name} Tenant",
        'email' => fake()->unique()->safeEmail(),
        'primary_phone' => fake()->unique()->e164PhoneNumber(),
        'id_type' => 'national_id',
        'id_number' => 'NIN-'.fake()->unique()->randomNumber(8),
        'emergency_contact_name' => 'Next of kin',
        'emergency_contact_relationship' => 'family',
        'emergency_contact_phone' => '+256700000000',
    ]);
    CurrentLandlord::clear();

    return compact('landlord', 'admin', 'unit', 'tenant');
}

/** Staff user who has opened $landlord's portal. */
function staffInPortal($test, Landlord $landlord, array $attributes = []): StaffUser
{
    $staff = StaffUser::factory()->create($attributes);
    $test->actingAs($staff, 'staff')->post(route('staff.landlords.portal', $landlord))->assertRedirect(route('dashboard'));

    return $staff;
}

// --- Entering and leaving ---

test('staff can open a landlord portal and see staff mode', function () {
    ['landlord' => $landlord] = portalLandlord();
    staffInPortal($this, $landlord);

    // (The dashboard itself uses MySQL-only SQL, so the tests look at another page.)
    $this->get(route('tenants.index'))
        ->assertOk()
        ->assertSee('Staff mode')
        ->assertSee('Acme Properties')
        ->assertSee('Exit staff mode');
});

test('staff without an open portal are sent back to the staff portal', function () {
    $staff = StaffUser::factory()->create();

    $this->actingAs($staff, 'staff')
        ->get(route('invoices.index'))
        ->assertRedirect(route('staff.landlords.index'));
});

test('guests still go to the landlord login', function () {
    $this->get(route('invoices.index'))->assertRedirect(route('login'));
});

test('leaving staff mode closes the portal', function () {
    ['landlord' => $landlord] = portalLandlord();
    staffInPortal($this, $landlord);

    $this->post(route('staff.portal.exit'))->assertRedirect(route('staff.landlords.show', $landlord));

    expect(session(StaffActingSession::SESSION_KEY))->toBeNull();
    $this->get(route('dashboard'))->assertRedirect(route('staff.landlords.index'));
});

// --- Isolation ---

test('staff mode only shows the chosen landlord\'s data, and switching landlords switches data', function () {
    ['landlord' => $a] = portalLandlord('Acme Properties');
    ['landlord' => $b] = portalLandlord('Beta Estates');
    $staff = staffInPortal($this, $a);

    $this->get(route('tenants.index'))
        ->assertSee('Acme Properties Tenant')
        ->assertDontSee('Beta Estates Tenant');

    $this->actingAs($staff, 'staff')->post(route('staff.landlords.portal', $b));

    $this->get(route('tenants.index'))
        ->assertSee('Beta Estates Tenant')
        ->assertDontSee('Acme Properties Tenant');
});

test('staff mode cannot reach another landlord\'s records by id', function () {
    ['landlord' => $a] = portalLandlord('Acme Properties');
    ['tenant' => $foreignTenant] = portalLandlord('Beta Estates');
    staffInPortal($this, $a);

    $this->put(route('tenants.update', $foreignTenant->id), ['name' => 'Hijacked'])->assertNotFound();
});

// --- Real work on the landlord's behalf ---

test('staff can create a lease, which raises invoices, then record and allocate a payment', function () {
    ['landlord' => $landlord, 'unit' => $unit, 'tenant' => $tenant] = portalLandlord();
    $staff = staffInPortal($this, $landlord);

    $this->post(route('leases.store'), [
        'tenant_id' => $tenant->id,
        'unit_id' => $unit->id,
        'start_date' => today()->toDateString(),
        'rent_amount' => 500000,
        'billing_cycle' => 'monthly',
        'security_deposit' => 0,
        'required_upfront_periods' => 1,
    ])->assertSessionHas('success');

    $invoice = Invoice::withoutGlobalScopes()->where('landlord_id', $landlord->id)->firstOrFail();
    expect((float) $invoice->amount)->toBe(500000.0)
        ->and($invoice->number)->toBe('INV-00001');
    $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'occupied']);

    $this->post(route('payments.store'), [
        'tenant_id' => $tenant->id,
        'amount' => 500000,
        'payment_date' => today()->toDateString(),
        'payment_method' => 'mtn_mobile_money',
    ])->assertSessionHas('success');

    $paymentId = DB::table('payments')->where('landlord_id', $landlord->id)->value('id');

    $this->post(route('payment-allocations.store'), [
        'payment_id' => $paymentId,
        'invoice_id' => $invoice->id,
        'amount' => 500000,
    ])->assertSessionHas('success');

    expect(DB::table('invoices')->find($invoice->id)->status)->toBe('paid');

    // Every change is attributed to the staff member, never to a landlord user.
    $audits = Audit::where('landlord_id', $landlord->id)->whereIn('auditable_type', [
        \App\Models\Lease::class, \App\Models\Payment::class, \App\Models\PaymentAllocation::class,
    ])->get();
    expect($audits)->not->toBeEmpty()
        ->and($audits->pluck('staff_user_id')->unique()->all())->toBe([$staff->id])
        ->and($audits->pluck('user_id')->filter()->all())->toBe([]);
});

test('staff can record a manual invoice for a customer', function () {
    ['landlord' => $landlord] = portalLandlord();
    staffInPortal($this, $landlord);

    $this->post(route('customers.store'), ['name' => 'Walk-in Ltd', 'phone' => '0771234567'])->assertSessionHas('success');

    $customerId = DB::table('customers')->where('landlord_id', $landlord->id)->value('id');

    $this->post(route('invoices.store'), [
        'customer_id' => $customerId,
        'type' => 'utility',
        'title' => 'Water — October',
        'invoice_date' => today()->toDateString(),
        'due_date' => today()->addDays(14)->toDateString(),
        'amount' => 45000,
    ])->assertSessionHas('success');

    $this->assertDatabaseHas('invoices', ['landlord_id' => $landlord->id, 'title' => 'Water — October', 'amount' => 45000]);
});

// --- Admin areas and the audit log ---

test('staff in a portal can open the landlord\'s admin areas', function () {
    ['landlord' => $landlord] = portalLandlord();
    staffInPortal($this, $landlord, ['role' => 'user']);

    foreach (['users.index', 'audits.index', 'sms-logs.index', 'company-profile.edit'] as $route) {
        $this->get(route($route))->assertOk();
    }
});

test('landlord non-admin users still cannot open admin areas', function () {
    ['landlord' => $landlord] = portalLandlord();
    $user = User::factory()->create(['landlord_id' => $landlord->id, 'role' => 'user']);

    $this->actingAs($user)->get(route('users.index'))->assertForbidden();
});

test('the landlord audit log names the staff member', function () {
    ['landlord' => $landlord] = portalLandlord();
    staffInPortal($this, $landlord, ['name' => 'Grace Support']);

    $this->post(route('customers.store'), ['name' => 'Audit Test Ltd', 'phone' => '0772345678']);

    $this->get(route('audits.index'))->assertSee('Grace Support (Primora staff)');
});

test('a staff id that matches a landlord user id does not hide that user\'s deactivate action', function () {
    ['landlord' => $landlord, 'admin' => $admin] = portalLandlord();
    $other = User::factory()->admin()->create(['landlord_id' => $landlord->id]);
    $staff = staffInPortal($this, $landlord);

    // Pretend the ids collide: the users page must compare people, not bare ids.
    expect($admin->is($staff))->toBeFalse();

    $this->get(route('users.index'))
        ->assertSee(route('users.toggle-active', $admin), false)
        ->assertSee(route('users.toggle-active', $other), false);
});

// --- Landlord users are unaffected ---

test('a landlord user still lands in their own portal without staff mode', function () {
    ['admin' => $admin] = portalLandlord();

    $this->actingAs($admin)
        ->get(route('tenants.index'))
        ->assertOk()
        ->assertDontSee('Staff mode');
});

test('staff can act on records loaded from the URL, like cancelling an invoice or opening its PDF', function () {
    ['landlord' => $landlord, 'unit' => $unit, 'tenant' => $tenant] = portalLandlord();
    staffInPortal($this, $landlord);

    $this->post(route('leases.store'), [
        'tenant_id' => $tenant->id,
        'unit_id' => $unit->id,
        'start_date' => today()->toDateString(),
        'rent_amount' => 300000,
        'billing_cycle' => 'monthly',
        'security_deposit' => 0,
        'required_upfront_periods' => 1,
    ])->assertSessionHas('success');

    $invoiceId = DB::table('invoices')->where('landlord_id', $landlord->id)->value('id');

    $this->get(route('invoices.pdf', $invoiceId))->assertOk();
    $this->patch(route('invoices.cancel', $invoiceId))->assertSessionHas('success');

    expect(DB::table('invoices')->find($invoiceId)->status)->toBe('cancelled');
});
