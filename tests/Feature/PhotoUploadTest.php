<?php

use App\Models\Estate;
use App\Models\Landlord;
use App\Models\Location;
use App\Models\StaffUser;
use App\Models\Unit;
use App\Models\User;
use App\Services\CurrentLandlord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

afterEach(fn () => CurrentLandlord::clear());

/** A landlord user plus one location and estate, with the context cleared again. */
function photoFixture(): array
{
    $user = User::factory()->create();
    CurrentLandlord::set($user->landlord_id);

    $location = Location::create(['name' => 'Ntinda', 'country' => 'Uganda', 'district' => 'Kampala', 'address' => 'Plot 1']);
    $estate = Estate::create(['name' => 'Sunrise', 'location_id' => $location->id]);

    CurrentLandlord::clear();

    return [$user, $location, $estate];
}

function estateRow(int $id): object
{
    return DB::table('estates')->find($id);
}

test('a landlord can add an estate with a photo, which is resized', function () {
    [$user, $location] = photoFixture();

    $this->actingAs($user)
        ->post(route('estates.store'), [
            'name' => 'Kololo Heights',
            'location_id' => $location->id,
            'photo' => UploadedFile::fake()->image('front.jpg', 3200, 2400),
        ])->assertSessionHas('success');

    $estate = DB::table('estates')->where('name', 'Kololo Heights')->first();

    expect($estate->photo)->toStartWith('estates/photos/');
    Storage::disk('public')->assertExists($estate->photo);

    if (function_exists('exif_read_data')) {
        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($estate->photo));
        expect(max($width, $height))->toBe(1600);
    }
});

test('uploading a new photo replaces and deletes the old one', function () {
    [$user, , $estate] = photoFixture();

    $this->actingAs($user)->put(route('estates.update', $estate), [
        'photo' => UploadedFile::fake()->image('first.jpg', 800, 600),
    ]);
    $first = estateRow($estate->id)->photo;

    $this->actingAs($user)->put(route('estates.update', $estate), [
        'photo' => UploadedFile::fake()->image('second.png', 800, 600),
    ]);
    $second = estateRow($estate->id)->photo;

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});

test('ticking remove photo clears it and deletes the file', function () {
    [$user, , $estate] = photoFixture();

    $this->actingAs($user)->put(route('estates.update', $estate), [
        'photo' => UploadedFile::fake()->image('front.jpg', 800, 600),
    ]);
    $path = estateRow($estate->id)->photo;

    $this->actingAs($user)->put(route('estates.update', $estate), ['remove_photo' => '1'])
        ->assertSessionHas('success');

    expect(estateRow($estate->id)->photo)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('editing other fields keeps the existing photo', function () {
    [$user, , $estate] = photoFixture();

    $this->actingAs($user)->put(route('estates.update', $estate), [
        'photo' => UploadedFile::fake()->image('front.jpg', 800, 600),
    ]);
    $path = estateRow($estate->id)->photo;

    $this->actingAs($user)->put(route('estates.update', $estate), ['name' => 'Sunrise Gardens']);

    expect(estateRow($estate->id)->photo)->toBe($path);
    Storage::disk('public')->assertExists($path);
});

test('deleting a unit deletes its photo', function () {
    [$user, , $estate] = photoFixture();

    $this->actingAs($user)->post(route('units.store'), [
        'name' => 'A1',
        'status' => 'vacant',
        'estate_id' => $estate->id,
        'photo' => UploadedFile::fake()->image('door.jpg', 600, 800),
    ]);
    $unit = DB::table('units')->where('name', 'A1')->first();
    Storage::disk('public')->assertExists($unit->photo);

    $this->actingAs($user)->delete(route('units.destroy', $unit->id))->assertSessionHas('success');

    Storage::disk('public')->assertMissing($unit->photo);
});

test('non-image files and oversized photos are rejected', function () {
    [$user, , $estate] = photoFixture();

    $this->actingAs($user)->put(route('estates.update', $estate), [
        'photo' => UploadedFile::fake()->create('lease.pdf', 200, 'application/pdf'),
    ])->assertSessionHasErrors('photo');

    $this->actingAs($user)->put(route('estates.update', $estate), [
        'photo' => UploadedFile::fake()->image('huge.jpg')->size(11 * 1024),
    ])->assertSessionHasErrors('photo');

    expect(estateRow($estate->id)->photo)->toBeNull();
});

test('photo URLs reach the page so they can be shown', function () {
    [$user, , $estate] = photoFixture();

    $this->actingAs($user)->put(route('estates.update', $estate), [
        'photo' => UploadedFile::fake()->image('front.jpg', 800, 600),
    ]);
    $path = estateRow($estate->id)->photo;

    $this->actingAs($user)->get(route('estates.index'))
        ->assertOk()
        ->assertSee(Storage::disk('public')->url($path), false);
});

test('staff can attach a unit photo on a landlord\'s behalf', function () {
    [$user, , $estate] = photoFixture();
    $staff = StaffUser::factory()->create();
    $landlord = Landlord::find($user->landlord_id);

    $this->actingAs($staff, 'staff')
        ->post(route('staff.landlords.units.store', $landlord), [
            'name' => 'B2',
            'status' => 'vacant',
            'estate_id' => $estate->id,
            'photo' => UploadedFile::fake()->image('b2.jpg', 1200, 900),
        ])->assertSessionHas('success');

    $unit = DB::table('units')->where('name', 'B2')->first();

    expect($unit->landlord_id)->toBe($landlord->id);
    Storage::disk('public')->assertExists($unit->photo);
});
