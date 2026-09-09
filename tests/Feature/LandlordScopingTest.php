<?php

use App\Exceptions\LandlordContextMissingException;
use App\Models\Estate;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\CurrentLandlord;

function createLeaseForLandlord(Landlord $landlord): Lease
{
    CurrentLandlord::set($landlord->id);

    $location = Location::create([
        'name' => 'Test Location',
        'country' => 'Uganda',
        'district' => 'Kampala',
        'address' => '123 Test St',
    ]);

    $estate = Estate::create([
        'name' => 'Test Estate',
        'location_id' => $location->id,
    ]);

    $unit = Unit::create([
        'name' => 'Unit A1',
        'estate_id' => $estate->id,
        'status' => 'vacant',
    ]);

    $tenant = Tenant::create([
        'type' => 'individual',
        'name' => 'Test Tenant',
        'email' => fake()->unique()->safeEmail(),
        'primary_phone' => fake()->unique()->e164PhoneNumber(),
        'id_type' => 'national_id',
        'id_number' => 'NIN-'.fake()->unique()->randomNumber(8),
        'emergency_contact_name' => 'Emergency Contact',
        'emergency_contact_relationship' => 'family',
        'emergency_contact_phone' => '+256700000000',
    ]);

    $lease = Lease::create([
        'tenant_id' => $tenant->id,
        'unit_id' => $unit->id,
        'status' => 'active',
        'start_date' => '2026-01-01',
        'rent_amount' => 500000,
        'billing_cycle' => 'monthly',
        'security_deposit' => 0,
        'required_upfront_periods' => 1,
        'next_invoice_date' => today()->format('Y-m-d'),
    ]);

    CurrentLandlord::clear();

    return $lease;
}

test('a landlord user cannot access another landlord\'s lease via route model binding', function () {
    $landlordA = Landlord::factory()->create();
    $landlordB = Landlord::factory()->create();

    $leaseB = createLeaseForLandlord($landlordB);

    $userA = User::factory()->admin()->create(['landlord_id' => $landlordA->id]);

    $this->actingAs($userA)
        ->get("/leases/{$leaseB->id}/deposit-balance")
        ->assertNotFound();
});

test('creating a landlord-scoped model with no landlord context throws', function () {
    CurrentLandlord::clear();

    expect(fn () => Location::create([
        'name' => 'No Context',
        'country' => 'Uganda',
        'district' => 'Kampala',
        'address' => '123 Test St',
    ]))->toThrow(LandlordContextMissingException::class);
});

test('invoices:generate-recurring only advances the landlord it is currently iterating', function () {
    $landlordA = Landlord::factory()->create();
    $landlordB = Landlord::factory()->create();

    $leaseA = createLeaseForLandlord($landlordA);
    $leaseB = createLeaseForLandlord($landlordB);

    $this->artisan('invoices:generate-recurring')->assertSuccessful();

    CurrentLandlord::set($landlordA->id);
    expect($leaseA->fresh()->next_invoice_date->gt(today()))->toBeTrue();
    CurrentLandlord::clear();

    CurrentLandlord::set($landlordB->id);
    expect($leaseB->fresh()->next_invoice_date->gt(today()))->toBeTrue();
    CurrentLandlord::clear();
});
