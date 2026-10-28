<?php

use App\Models\Estate;
use App\Models\Landlord;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\CurrentLandlord;

afterEach(fn () => CurrentLandlord::clear());

/** A landlord user with one estate (no coordinates yet). */
function coordinatesFixture(): array
{
    $user = User::factory()->create();
    CurrentLandlord::set($user->landlord_id);

    $estate = Estate::create(['name' => 'Hilltop', 'district' => 'Kampala']);

    CurrentLandlord::clear();

    return [$user, null, $estate];
}

test('an estate can be created with map coordinates', function () {
    [$user] = coordinatesFixture();

    $this->actingAs($user)->post(route('estates.store'), [
        'name' => 'Kololo Heights',
        'district' => 'Kampala',
        'latitude' => '0.3350123',
        'longitude' => '32.5946789',
    ])->assertSessionHas('success');

    $row = DB::table('estates')->where('name', 'Kololo Heights')->first();

    expect((float) $row->latitude)->toBe(0.3350123)
        ->and((float) $row->longitude)->toBe(32.5946789);
});

test('coordinates are optional', function () {
    [$user] = coordinatesFixture();

    $this->actingAs($user)->post(route('estates.store'), [
        'name' => 'No Pin Estate',
        'district' => 'Kampala',
        'latitude' => '',
        'longitude' => '',
    ])->assertSessionHas('success');

    $row = DB::table('estates')->where('name', 'No Pin Estate')->first();
    expect($row->latitude)->toBeNull()->and($row->longitude)->toBeNull();
});

test('coordinates can be changed and cleared', function () {
    [$user, , $estate] = coordinatesFixture();

    $this->actingAs($user)->put(route('estates.update', $estate), ['latitude' => '0.31', 'longitude' => '32.58']);
    expect((float) DB::table('estates')->find($estate->id)->latitude)->toBe(0.31);

    $this->actingAs($user)->put(route('estates.update', $estate), ['latitude' => '', 'longitude' => ''])
        ->assertSessionHas('success');

    $row = DB::table('estates')->find($estate->id);
    expect($row->latitude)->toBeNull()->and($row->longitude)->toBeNull();
});

test('editing other fields leaves the coordinates alone', function () {
    [$user, , $estate] = coordinatesFixture();

    $this->actingAs($user)->put(route('estates.update', $estate), ['latitude' => '0.31', 'longitude' => '32.58']);
    $this->actingAs($user)->put(route('estates.update', $estate), ['name' => 'Hilltop Gardens']);

    $row = DB::table('estates')->find($estate->id);
    expect((float) $row->latitude)->toBe(0.31)->and((float) $row->longitude)->toBe(32.58);
});

test('out-of-range or half-filled coordinates are rejected', function (array $coords, string $field) {
    [$user, , $estate] = coordinatesFixture();

    $this->actingAs($user)->put(route('estates.update', $estate), $coords)
        ->assertSessionHasErrors($field);

    expect(DB::table('estates')->find($estate->id)->latitude)->toBeNull();
})->with([
    'latitude too large' => [['latitude' => '95', 'longitude' => '32.5'], 'latitude'],
    'longitude too small' => [['latitude' => '0.3', 'longitude' => '-200'], 'longitude'],
    'not a number' => [['latitude' => 'abc', 'longitude' => '32.5'], 'latitude'],
    'latitude without longitude' => [['latitude' => '0.3'], 'longitude'],
    'longitude without latitude' => [['longitude' => '32.5'], 'latitude'],
]);

test('estates with coordinates get a map link in the list', function () {
    [$user, , $estate] = coordinatesFixture();

    $this->actingAs($user)->put(route('estates.update', $estate), ['latitude' => '0.3350123', 'longitude' => '32.5946789']);

    $this->actingAs($user)->get(route('estates.index'))
        ->assertOk()
        ->assertSee('https://www.google.com/maps?q=0.3350123,32.5946789', false);
});

test('staff can set an estate\'s coordinates on a landlord\'s behalf', function () {
    [$user, , $estate] = coordinatesFixture();
    $staff = StaffUser::factory()->create();
    $landlord = Landlord::find($user->landlord_id);

    $this->actingAs($staff, 'staff')
        ->put(route('staff.landlords.estates.update', [$landlord, $estate->id]), ['latitude' => '0.3476', 'longitude' => '32.5825'])
        ->assertSessionHas('success');

    $row = DB::table('estates')->find($estate->id);
    expect((float) $row->latitude)->toBe(0.3476)->and((float) $row->longitude)->toBe(32.5825);

    $this->actingAs($staff, 'staff')
        ->get(route('staff.landlords.show', $landlord))
        ->assertSee('https://www.google.com/maps?q=0.3476,32.5825', false);
});
