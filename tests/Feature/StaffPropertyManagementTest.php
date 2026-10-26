<?php

use App\Models\Audit;
use App\Models\Estate;
use App\Models\Landlord;
use App\Models\Location;
use App\Models\StaffUser;
use App\Models\Unit;
use App\Models\User;
use App\Services\CurrentLandlord;
use App\Services\PropertyMetrics;

/**
 * Seeds one location → one estate → units with the given statuses for a landlord.
 *
 * @return array{location: Location, estate: Estate, units: \Illuminate\Support\Collection}
 */
function seedPortfolio(Landlord $landlord, array $statuses = ['occupied', 'vacant']): array
{
    CurrentLandlord::set($landlord->id);

    $location = Location::create(['name' => 'Ntinda', 'country' => 'Uganda', 'district' => 'Kampala', 'address' => 'Plot 1']);
    $estate = Estate::create(['name' => $landlord->name.' Courts', 'location_id' => $location->id]);
    $units = collect($statuses)->map(fn ($status, $i) => Unit::create([
        'name' => 'Unit '.($i + 1),
        'estate_id' => $estate->id,
        'status' => $status,
    ]));

    CurrentLandlord::clear();

    return compact('location', 'estate', 'units');
}

afterEach(fn () => CurrentLandlord::clear());

// --- Access ---

test('staff can open every section of a landlord workspace', function () {
    $staff = StaffUser::factory()->create();
    $landlord = Landlord::factory()->create();
    seedPortfolio($landlord);

    foreach (['show', 'locations.index', 'estates.index', 'units.index'] as $route) {
        $this->actingAs($staff, 'staff')
            ->get(route('staff.landlords.'.$route, $landlord))
            ->assertOk();
    }
});

test('guests are sent to the staff login from a landlord workspace', function () {
    $landlord = Landlord::factory()->create();

    $this->get(route('staff.landlords.estates.index', $landlord))
        ->assertRedirect(route('staff.login'));
});

test('landlord-side users cannot reach the staff landlord workspace', function () {
    $landlord = Landlord::factory()->create();
    $user = User::factory()->create(['landlord_id' => $landlord->id]);

    $this->actingAs($user, 'web')
        ->get(route('staff.landlords.units.index', $landlord))
        ->assertRedirect(route('staff.login'));
});

test('the landlord context does not leak past the request', function () {
    $staff = StaffUser::factory()->create();
    $landlord = Landlord::factory()->create();

    $this->actingAs($staff, 'staff')->get(route('staff.landlords.show', $landlord))->assertOk();

    expect(CurrentLandlord::id())->toBeNull();
});

// --- Workspace lists only that landlord's data ---

test('workspace lists only the selected landlord\'s estates and units', function () {
    $staff = StaffUser::factory()->create();
    $mine = Landlord::factory()->create(['name' => 'Acme']);
    $other = Landlord::factory()->create(['name' => 'Beta']);
    seedPortfolio($mine);
    seedPortfolio($other);

    $this->actingAs($staff, 'staff')
        ->get(route('staff.landlords.estates.index', $mine))
        ->assertSee('Acme Courts')
        ->assertDontSee('Beta Courts');
});

// --- Create on behalf of a landlord ---

test('staff can create a location, estate and unit for a landlord', function () {
    $staff = StaffUser::factory()->create();
    $landlord = Landlord::factory()->create();

    $this->actingAs($staff, 'staff')
        ->post(route('staff.landlords.locations.store', $landlord), [
            'name' => 'Kololo', 'country' => 'Uganda', 'district' => 'Kampala', 'address' => 'Plot 9',
        ])->assertSessionHas('success');

    $locationId = Location::withoutGlobalScopes()->where('name', 'Kololo')->value('id');

    $this->actingAs($staff, 'staff')
        ->post(route('staff.landlords.estates.store', $landlord), [
            'name' => 'Kololo Heights', 'location_id' => $locationId,
        ])->assertSessionHas('success');

    $estateId = Estate::withoutGlobalScopes()->where('name', 'Kololo Heights')->value('id');

    $this->actingAs($staff, 'staff')
        ->post(route('staff.landlords.units.store', $landlord), [
            'name' => 'A1', 'status' => 'vacant', 'estate_id' => $estateId,
        ])->assertSessionHas('success');

    $this->assertDatabaseHas('locations', ['name' => 'Kololo', 'landlord_id' => $landlord->id]);
    $this->assertDatabaseHas('estates', ['name' => 'Kololo Heights', 'landlord_id' => $landlord->id]);
    $this->assertDatabaseHas('units', ['name' => 'A1', 'estate_id' => $estateId, 'landlord_id' => $landlord->id]);
});

test('changes made by staff are audited under the staff user and the landlord', function () {
    $staff = StaffUser::factory()->create();
    $landlord = Landlord::factory()->create();
    ['estate' => $estate] = seedPortfolio($landlord, []);

    $this->actingAs($staff, 'staff')
        ->post(route('staff.landlords.units.store', $landlord), [
            'name' => 'B2', 'status' => 'vacant', 'estate_id' => $estate->id,
        ]);

    $audit = Audit::where('auditable_type', Unit::class)->where('action', 'created')->latest('id')->first();

    expect($audit->staff_user_id)->toBe($staff->id)
        ->and($audit->user_id)->toBeNull()
        ->and($audit->landlord_id)->toBe($landlord->id);
});

// --- Cross-landlord protection ---

test('staff cannot attach an estate to another landlord\'s location', function () {
    $staff = StaffUser::factory()->create();
    $landlord = Landlord::factory()->create();
    $other = Landlord::factory()->create();
    ['location' => $foreignLocation] = seedPortfolio($other);

    $this->actingAs($staff, 'staff')
        ->post(route('staff.landlords.estates.store', $landlord), [
            'name' => 'Sneaky Estate', 'location_id' => $foreignLocation->id,
        ])->assertSessionHasErrors('location_id');

    $this->assertDatabaseMissing('estates', ['name' => 'Sneaky Estate']);
});

test('a unit cannot be edited through a different landlord\'s workspace', function () {
    $staff = StaffUser::factory()->create();
    $landlord = Landlord::factory()->create();
    $other = Landlord::factory()->create();
    ['units' => $units] = seedPortfolio($other, ['vacant']);

    $this->actingAs($staff, 'staff')
        ->put(route('staff.landlords.units.update', [$landlord, $units[0]->id]), ['name' => 'Hijacked'])
        ->assertNotFound();

    $this->assertDatabaseMissing('units', ['name' => 'Hijacked']);
});

// --- Same safety rules as the landlord side ---

test('an estate with units cannot be deleted', function () {
    $staff = StaffUser::factory()->create();
    $landlord = Landlord::factory()->create();
    ['estate' => $estate] = seedPortfolio($landlord, ['vacant']);

    $this->actingAs($staff, 'staff')
        ->delete(route('staff.landlords.estates.destroy', [$landlord, $estate->id]))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('estates', ['id' => $estate->id]);
});

test('staff can update a unit and move it into maintenance', function () {
    $staff = StaffUser::factory()->create();
    $landlord = Landlord::factory()->create();
    ['units' => $units] = seedPortfolio($landlord, ['vacant']);

    $this->actingAs($staff, 'staff')
        ->put(route('staff.landlords.units.update', [$landlord, $units[0]->id]), [
            'name' => 'Unit 1 (renovated)', 'status' => 'maintenance',
        ])->assertSessionHas('success');

    $this->assertDatabaseHas('units', ['id' => $units[0]->id, 'name' => 'Unit 1 (renovated)', 'status' => 'maintenance']);
});

test('staff can delete an empty unit', function () {
    $staff = StaffUser::factory()->create();
    $landlord = Landlord::factory()->create();
    ['units' => $units] = seedPortfolio($landlord, ['vacant']);

    $this->actingAs($staff, 'staff')
        ->delete(route('staff.landlords.units.destroy', [$landlord, $units[0]->id]))
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('units', ['id' => $units[0]->id]);
});

// --- Metrics ---

test('property metrics summarise one landlord or the whole platform', function () {
    $a = Landlord::factory()->create();
    $b = Landlord::factory()->create();
    seedPortfolio($a, ['occupied', 'occupied', 'vacant', 'maintenance']);
    seedPortfolio($b, ['vacant']);

    $metrics = app(PropertyMetrics::class);

    $forA = $metrics->summary($a->id);
    expect($forA['units'])->toBe(4)
        ->and($forA['occupied'])->toBe(2)
        ->and($forA['vacant'])->toBe(1)
        ->and($forA['maintenance'])->toBe(1)
        ->and($forA['occupancy_rate'])->toBe(50.0)
        ->and($forA['estates'])->toBe(1);

    $platform = $metrics->summary();
    expect($platform['units'])->toBe(5)
        ->and($platform['estates'])->toBe(2)
        ->and($platform['occupancy_rate'])->toBe(40.0);

    $top = $metrics->topLandlords();
    expect($top->first()->landlord->id)->toBe($a->id)
        ->and($top->first()->units)->toBe(4);
});

test('staff dashboard and landlord list show portfolio figures', function () {
    $staff = StaffUser::factory()->create();
    $landlord = Landlord::factory()->create(['name' => 'Portfolio Co']);
    seedPortfolio($landlord, ['occupied', 'vacant', 'vacant']);

    $this->actingAs($staff, 'staff')
        ->get(route('staff.dashboard'))
        ->assertOk()
        ->assertSee('Property portfolio')
        ->assertSee('Portfolio Co');

    $this->actingAs($staff, 'staff')
        ->get(route('staff.landlords.index'))
        ->assertOk()
        ->assertSee('3 units')
        ->assertSee('33% let');
});
