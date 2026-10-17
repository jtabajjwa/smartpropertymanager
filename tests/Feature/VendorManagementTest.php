<?php

use App\Models\Landlord;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CurrentLandlord;

// --- Access Control ---

test('user can access vendors page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('vendors.index'))
        ->assertOk();
});

test('guest cannot access vendors page', function () {
    $this->get(route('vendors.index'))
        ->assertRedirect(route('login'));
});

// --- Create Vendor ---

test('user can create a vendor', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('vendors.store'), [
            'name' => 'Acme Electrical Services',
            'category' => 'electrician',
            'contact_person' => 'John Doe',
            'phone' => '0771234567',
            'email' => 'acme@example.com',
            'address' => 'Kampala Road',
            'notes' => 'Reliable and fast.',
        ])->assertRedirect()->assertSessionHas('success');

    $this->assertDatabaseHas('vendors', [
        'landlord_id' => $user->landlord_id,
        'name' => 'Acme Electrical Services',
        'category' => 'electrician',
        'contact_person' => 'John Doe',
    ]);
});

test('vendor name is required', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('vendors.store'), [
            'category' => 'plumber',
        ])->assertRedirect()->assertSessionHasErrors('name');
});

test('vendor category must be a valid option', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('vendors.store'), [
            'name' => 'Some Vendor',
            'category' => 'not-a-real-category',
        ])->assertRedirect()->assertSessionHasErrors('category');
});

// --- Update Vendor ---

test('user can update a vendor', function () {
    $user = User::factory()->create();
    $vendor = Vendor::factory()->create(['landlord_id' => $user->landlord_id, 'name' => 'Old Name']);

    $this->actingAs($user)
        ->put(route('vendors.update', $vendor), [
            'name' => 'New Name',
            'category' => 'security',
        ])->assertRedirect()->assertSessionHas('success');

    expect($vendor->fresh()->name)->toBe('New Name');
    expect($vendor->fresh()->category)->toBe('security');
});

// --- Delete Vendor ---

test('user can delete a vendor', function () {
    $user = User::factory()->create();
    $vendor = Vendor::factory()->create(['landlord_id' => $user->landlord_id]);

    $this->actingAs($user)
        ->delete(route('vendors.destroy', $vendor))
        ->assertRedirect()->assertSessionHas('success');

    $this->assertDatabaseMissing('vendors', ['id' => $vendor->id]);
});

// --- Landlord Isolation (Tier A scoping) ---

test('vendors index only shows the current landlord\'s vendors', function () {
    $landlordA = Landlord::factory()->create();
    $landlordB = Landlord::factory()->create();

    CurrentLandlord::set($landlordA->id);
    Vendor::create(['name' => 'Vendor A', 'category' => 'plumber']);
    CurrentLandlord::clear();

    CurrentLandlord::set($landlordB->id);
    Vendor::create(['name' => 'Vendor B', 'category' => 'electrician']);
    CurrentLandlord::clear();

    $userA = User::factory()->create(['landlord_id' => $landlordA->id]);

    $response = $this->actingAs($userA)->get(route('vendors.index'));

    $response->assertOk();
    $response->assertSee('Vendor A');
    $response->assertDontSee('Vendor B');
});

test('a landlord user cannot update another landlord\'s vendor via route model binding', function () {
    $landlordA = Landlord::factory()->create();
    $landlordB = Landlord::factory()->create();

    CurrentLandlord::set($landlordB->id);
    $vendorB = Vendor::create(['name' => 'Vendor B', 'category' => 'electrician']);
    CurrentLandlord::clear();

    $userA = User::factory()->create(['landlord_id' => $landlordA->id]);

    $this->actingAs($userA)
        ->put(route('vendors.update', $vendorB), [
            'name' => 'Hacked Name',
            'category' => 'electrician',
        ])->assertNotFound();

    expect($vendorB->fresh()->name)->toBe('Vendor B');
});

test('creating a vendor with no landlord context throws', function () {
    CurrentLandlord::clear();

    expect(fn () => Vendor::create([
        'name' => 'No Context',
        'category' => 'other',
    ]))->toThrow(\App\Exceptions\LandlordContextMissingException::class);
});
