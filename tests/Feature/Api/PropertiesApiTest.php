<?php

use App\Models\Estate;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Unit;
use App\Services\CurrentLandlord;

/*
 * Mobile API, landlord phase 1b: properties, units and leases, using the
 * same rules as the web (unit limit, lease start invoices, locked terms).
 */

afterEach(fn () => CurrentLandlord::clear());

test('properties list with occupancy, and a property shows its units and who is in them', function () {
    $mine = apiLandlord();
    $theirs = apiLandlord();
    $token = apiToken($this, $mine['admin']);

    $this->withToken($token)->getJson(route('api.v1.estates.index'))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Acacia Court')->assertJsonPath('data.0.unit_count', 1)->assertJsonPath('data.0.occupied_count', 1);

    $estateId = $mine['unit']->estate_id;
    $this->withToken($token)->getJson(route('api.v1.estates.show', $estateId))->assertOk()
        ->assertJsonPath('units.0.name', 'A4')->assertJsonPath('units.0.occupant.tenant', 'Grace Namata')->assertJsonPath('units.0.occupant.owed', 500000);

    $this->withToken($token)->getJson(route('api.v1.estates.show', $theirs['unit']->estate_id))->assertNotFound();
    $this->withToken($token)->getJson(route('api.v1.estates.places'))->assertOk()->assertJsonFragment(['Kampala']);
});

test('adding a property and units follows the web rules, including the plan\'s unit limit', function () {
    ['admin' => $admin, 'landlord' => $landlord] = apiLandlord();
    $token = apiToken($this, $admin);

    $this->withToken($token)->postJson(route('api.v1.estates.store'), ['name' => 'Ntinda Flats', 'country' => 'Uganda', 'district' => 'Atlantis'])
        ->assertUnprocessable()->assertJsonValidationErrors('district');
    $estate = $this->withToken($token)->postJson(route('api.v1.estates.store'), ['name' => 'Ntinda Flats', 'country' => 'Uganda', 'district' => 'Kampala'])
        ->assertCreated()->json();

    $this->withToken($token)->postJson(route('api.v1.units.store'), ['name' => 'B1', 'estate_id' => $estate['id'], 'status' => 'vacant'])->assertCreated()->assertJsonPath('label', 'Ntinda Flats · B1');

    // The plan covers 2 units: a third is refused.
    $landlord->update(['max_units' => 2]);
    $this->withToken($token)->postJson(route('api.v1.units.store'), ['name' => 'B2', 'estate_id' => $estate['id'], 'status' => 'vacant'])
        ->assertUnprocessable()->assertJsonValidationErrors('units');

    $this->withToken($token)->getJson(route('api.v1.units.index', ['status' => 'vacant']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'B1');
});

test('a lease started in the app raises its first invoices and fills the unit; its money terms then lock', function () {
    ['admin' => $admin, 'tenant' => $tenant, 'unit' => $unit] = apiLandlord();
    $token = apiToken($this, $admin);
    $vacant = CurrentLandlord::runAs($admin->landlord_id, fn () => Unit::create(['name' => 'A5', 'estate_id' => $unit->estate_id, 'status' => 'vacant']));
    CurrentLandlord::clear();

    $lease = $this->withToken($token)->postJson(route('api.v1.leases.store'), [
        'tenant_id' => $tenant->id, 'unit_id' => $vacant->id, 'start_date' => '2026-12-01', 'rent_amount' => 300000, 'billing_cycle' => 'monthly',
        'security_deposit' => 300000, 'required_upfront_periods' => 3,
    ])->assertCreated()->assertJsonPath('invoices_created', 2)->assertJsonPath('next_invoice_date', '2027-03-01')->json();

    expect($vacant->fresh()->status)->toBe('occupied')
        ->and(Invoice::withoutGlobalScopes()->where('lease_id', $lease['id'])->pluck('amount')->map(fn ($a) => (float) $a)->sort()->values()->all())->toBe([300000.0, 900000.0]);

    $this->withToken($token)->getJson(route('api.v1.leases.show', $lease['id']))->assertOk()
        ->assertJsonPath('has_invoices', true)->assertJsonCount(2, 'invoices')->assertJsonPath('locked_fields.0', 'rent_amount');

    // Rent can't change once invoiced; ending the lease frees the unit.
    $this->withToken($token)->postJson(route('api.v1.leases.update', $lease['id']), ['rent_amount' => 1, 'end_date' => '2027-06-30'])->assertOk()->assertJsonPath('rent_amount', 300000);
    $this->withToken($token)->postJson(route('api.v1.leases.update', $lease['id']), ['status' => 'terminated'])->assertOk()->assertJsonPath('status', 'terminated');
    expect($vacant->fresh()->status)->toBe('vacant');

    $this->withToken($token)->postJson(route('api.v1.leases.update', $lease['id']), ['end_date' => '2027-07-31'])->assertUnprocessable()->assertJsonValidationErrors('status');
});
