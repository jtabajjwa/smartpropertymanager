<?php

use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\StaffUser;
use App\Services\CurrentLandlord;
use App\Support\StaffPermissions;

afterEach(fn () => CurrentLandlord::clear());

/** Records the routes act on. */
function permissionFixtures(): array
{
    $landlord = Landlord::factory()->create();
    $plan = Plan::factory()->create();
    $bundle = \App\Models\SmsBundle::create(['name' => 'B', 'quantity' => 10, 'price' => 500]);
    $purchase = \App\Models\SmsPurchase::create(['provider' => 'Marz', 'quantity' => 10, 'total_cost' => 200, 'purchased_on' => today()]);
    $payment = PaymentTransaction::create([
        'reference' => 'SUB-261027-TESTAA', 'purpose' => 'subscription', 'landlord_id' => $landlord->id,
        'gateway' => 'fake', 'amount' => 15000, 'msisdn' => '256772123456', 'narrative' => 'test', 'status' => 'pending',
    ]);

    return compact('landlord', 'plan', 'payment', 'bundle', 'purchase');
}

/**
 * Every guarded staff route: [method, route name, params(fixtures), permission].
 * Requests carry no valid payload; the point is only whether the permission gate lets them through.
 */
function guardedRoutes(): array
{
    return [
        ['get', 'staff.landlords.index', fn ($f) => [], 'landlords.view'],
        ['post', 'staff.landlords.store', fn ($f) => [], 'landlords.create'],
        ['put', 'staff.landlords.update', fn ($f) => [$f['landlord']], 'landlords.edit'],
        ['patch', 'staff.landlords.suspend', fn ($f) => [$f['landlord']], 'landlords.suspend'],
        ['patch', 'staff.landlords.reactivate', fn ($f) => [$f['landlord']], 'landlords.suspend'],
        ['post', 'staff.landlords.portal', fn ($f) => [$f['landlord']], 'portal.access'],
        ['get', 'staff.landlords.show', fn ($f) => [$f['landlord']], 'properties.view'],
        ['get', 'staff.landlords.units.index', fn ($f) => [$f['landlord']], 'properties.view'],
        ['post', 'staff.landlords.units.store', fn ($f) => [$f['landlord']], 'properties.manage'],
        ['post', 'staff.landlords.estates.store', fn ($f) => [$f['landlord']], 'properties.manage'],
        ['delete', 'staff.landlords.locations.destroy', fn ($f) => [$f['landlord'], 999], 'properties.manage'],
        ['get', 'staff.plans.index', fn ($f) => [], 'plans.view'],
        ['post', 'staff.plans.store', fn ($f) => [], 'plans.manage'],
        ['delete', 'staff.plans.destroy', fn ($f) => [$f['plan']], 'plans.manage'],
        ['get', 'staff.subscriptions.index', fn ($f) => [], 'subscriptions.view'],
        ['get', 'staff.subscriptions.show', fn ($f) => [$f['landlord']], 'subscriptions.view'],
        ['put', 'staff.subscriptions.update', fn ($f) => [$f['landlord']], 'subscriptions.adjust'],
        ['post', 'staff.subscriptions.payments.store', fn ($f) => [$f['landlord']], 'subscriptions.record_payment'],
        ['get', 'staff.payments.index', fn ($f) => [], 'payments.view'],
        ['get', 'staff.payments.unmatched', fn ($f) => [], 'payments.view'],
        ['get', 'staff.payments.show', fn ($f) => [$f['payment']], 'payments.view'],
        ['post', 'staff.payments.check', fn ($f) => [$f['payment']], 'payments.check'],
        ['get', 'staff.sms.index', fn ($f) => [], 'sms.view'],
        ['get', 'staff.sms.landlords', fn ($f) => [], 'sms.view'],
        ['get', 'staff.sms.landlord', fn ($f) => [$f['landlord']], 'sms.view'],
        ['post', 'staff.sms.purchases.store', fn ($f) => [], 'sms.purchases'],
        ['put', 'staff.sms.purchases.update', fn ($f) => [$f['purchase']], 'sms.purchases'],
        ['delete', 'staff.sms.purchases.destroy', fn ($f) => [$f['purchase']], 'sms.purchases'],
        ['post', 'staff.sms.bundles.store', fn ($f) => [], 'sms.manage'],
        ['put', 'staff.sms.bundles.update', fn ($f) => [$f['bundle']], 'sms.manage'],
        ['delete', 'staff.sms.bundles.destroy', fn ($f) => [$f['bundle']], 'sms.manage'],
        ['put', 'staff.sms.settings.update', fn ($f) => [], 'sms.manage'],
        ['post', 'staff.sms.adjust', fn ($f) => [$f['landlord']], 'sms.credits'],
        ['post', 'staff.sms.sales.store', fn ($f) => [$f['landlord']], 'sms.credits'],
        ['get', 'staff.revenue.index', fn ($f) => [], 'revenue.view'],
        ['get', 'staff.collections.index', fn ($f) => [], 'collections.view'],
        ['get', 'staff.collections.show', fn ($f) => [$f['landlord']], 'collections.view'],
        ['post', 'staff.collections.toggle', fn ($f) => [$f['landlord']], 'collections.manage'],
        ['post', 'staff.collections.tiers.store', fn ($f) => [], 'collections.manage'],
        ['post', 'staff.collections.settlements.store', fn ($f) => [$f['landlord']], 'collections.settle'],
    ];
}

// --- Every route is guarded by its permission ---

test('each staff action needs its permission, and that permission alone is enough', function () {
    $f = permissionFixtures();

    foreach (guardedRoutes() as [$method, $name, $params, $permission]) {
        $url = route($name, $params($f));

        // Everything except this permission and anything that implies it.
        $others = array_filter(StaffPermissions::all(), fn ($p) => !in_array($permission, StaffPermissions::normalize([$p]), true));
        $denied = StaffUser::factory()->withPermissions($others)->create();
        $this->actingAs($denied, 'staff')->{$method}($url)->assertForbidden();

        $allowed = StaffUser::factory()->withPermissions([$permission])->create();
        $status = $this->actingAs($allowed, 'staff')->{$method}($url)->status();
        expect($status)->not->toBe(403, "{$name} should be allowed with [{$permission}]");
    }
});

test('staff with no permissions can still sign in and see the dashboard, but nothing else', function () {
    $staff = StaffUser::factory()->withoutPermissions()->create();

    $this->actingAs($staff, 'staff')->get(route('staff.dashboard'))->assertOk()
        ->assertDontSee(route('staff.landlords.index'))
        ->assertDontSee(route('staff.payments.index'));

    $this->actingAs($staff, 'staff')->get(route('staff.landlords.index'))->assertForbidden();
});

test('admins can do everything', function () {
    $f = permissionFixtures();
    $admin = StaffUser::factory()->admin()->create(['permissions' => []]);

    foreach (guardedRoutes() as [$method, $name, $params]) {
        expect($this->actingAs($admin, 'staff')->{$method}(route($name, $params($f)))->status())->not->toBe(403);
    }
});

test('managing something implies seeing it', function () {
    expect(StaffPermissions::normalize(['plans.manage', 'payments.check', 'nonsense']))
        ->toBe(['plans.view', 'plans.manage', 'payments.view', 'payments.check']);

    $staff = StaffUser::factory()->withPermissions(['properties.manage'])->create();
    $landlord = Landlord::factory()->create();
    $this->actingAs($staff, 'staff')->get(route('staff.landlords.units.index', $landlord))->assertOk();
});

// --- Staff mode (landlord portal) ---

test('without portal access, staff cannot open a landlord portal', function () {
    $landlord = Landlord::factory()->create();
    $staff = StaffUser::factory()->withPermissions(['landlords.view'])->create();

    $this->actingAs($staff, 'staff')->post(route('staff.landlords.portal', $landlord))->assertForbidden();
});

test('revoking portal access takes effect immediately, even mid-session', function () {
    $landlord = Landlord::factory()->create();
    $staff = StaffUser::factory()->withPermissions(['portal.access'])->create();

    $this->actingAs($staff, 'staff')->post(route('staff.landlords.portal', $landlord));
    $this->get(route('tenants.index'))->assertOk();

    $staff->update(['permissions' => []]);

    $this->get(route('tenants.index'))->assertRedirect(route('staff.dashboard'));
    expect(session(\App\Services\StaffActingSession::SESSION_KEY))->toBeNull();
});

test('the landlord\'s admin areas need the separate portal-admin permission', function () {
    $landlord = Landlord::factory()->create();

    $worker = StaffUser::factory()->withPermissions(['portal.access'])->create();
    $this->actingAs($worker, 'staff')->post(route('staff.landlords.portal', $landlord));
    $this->get(route('tenants.index'))->assertOk();
    $this->get(route('users.index'))->assertForbidden();
    $this->get(route('subscription.show'))->assertForbidden();

    $admin = StaffUser::factory()->withPermissions(['portal.admin'])->create();
    $this->actingAs($admin, 'staff')->post(route('staff.landlords.portal', $landlord));
    $this->get(route('users.index'))->assertOk();
});

// --- Screens only offer what you may do ---

test('buttons for actions a staff member may not take are hidden', function () {
    $landlord = Landlord::factory()->create();
    $viewer = StaffUser::factory()->withPermissions(['subscriptions.view', 'landlords.view'])->create();

    $this->actingAs($viewer, 'staff')->get(route('staff.subscriptions.show', $landlord))->assertOk()
        ->assertDontSee('Record an offline payment')
        ->assertDontSee('Adjust subscription');

    $this->actingAs($viewer, 'staff')->get(route('staff.landlords.index'))->assertOk()
        ->assertDontSee('Add Landlord')
        ->assertDontSee('Open landlord portal')
        ->assertDontSee('>Suspend</button>', false);

    $finance = StaffUser::factory()->withPermissions(['subscriptions.record_payment'])->create();
    $this->actingAs($finance, 'staff')->get(route('staff.subscriptions.show', $landlord))
        ->assertSee('Record an offline payment')
        ->assertDontSee('Adjust subscription');
});

// --- Managing staff and their permissions ---

test('only staff admins can manage staff accounts', function () {
    $everythingElse = StaffUser::factory()->create(); // every permission, but not admin

    $this->actingAs($everythingElse, 'staff')->get(route('staff.staff-users.index'))->assertForbidden();
});

test('an admin can create staff with chosen permissions; implied ones are added and unknown ones refused', function () {
    $admin = StaffUser::factory()->admin()->create();

    $this->actingAs($admin, 'staff')->post(route('staff.staff-users.store'), [
        'name' => 'Fiona Finance', 'email' => 'fiona@primora.test', 'password' => 'secret1234', 'role' => 'user',
        'permissions' => ['subscriptions.record_payment', 'payments.check'],
    ])->assertSessionHas('success');

    expect(StaffUser::where('email', 'fiona@primora.test')->first()->permissions)
        ->toBe(['subscriptions.view', 'subscriptions.record_payment', 'payments.view', 'payments.check']);

    $this->actingAs($admin, 'staff')->post(route('staff.staff-users.store'), [
        'name' => 'Bad', 'email' => 'bad@primora.test', 'password' => 'secret1234', 'role' => 'user',
        'permissions' => ['staff.manage'],
    ])->assertSessionHasErrors('permissions.0');
});

test('an admin can change another staff member\'s permissions, and it is audited', function () {
    $admin = StaffUser::factory()->admin()->create();
    $staff = StaffUser::factory()->withPermissions(['payments.view'])->create();

    $this->actingAs($admin, 'staff')->put(route('staff.staff-users.update', $staff), [
        'name' => $staff->name, 'email' => $staff->email, 'role' => 'user', 'permissions' => ['plans.manage'],
    ])->assertSessionHas('success');

    expect($staff->fresh()->permissions)->toBe(['plans.view', 'plans.manage']);

    $audit = \App\Models\Audit::where('auditable_type', StaffUser::class)->where('auditable_id', $staff->id)->where('action', 'updated')->latest('id')->first();
    expect($audit->staff_user_id)->toBe($admin->id)->and($audit->new_values)->toHaveKey('permissions');
});

test('nobody can change their own role or permissions', function () {
    $admin = StaffUser::factory()->admin()->create();
    StaffUser::factory()->admin()->create(); // so "last admin" isn't the reason

    $this->actingAs($admin, 'staff')->put(route('staff.staff-users.update', $admin), [
        'name' => $admin->name, 'email' => $admin->email, 'role' => 'user', 'permissions' => [],
    ])->assertSessionHas('error');

    expect($admin->fresh()->role)->toBe('admin');

    // Editing your own name is fine.
    $this->actingAs($admin, 'staff')->put(route('staff.staff-users.update', $admin), [
        'name' => 'New Name', 'email' => $admin->email, 'role' => 'admin',
    ])->assertSessionHas('success');
});
