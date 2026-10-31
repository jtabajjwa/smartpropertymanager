<?php

use App\Models\Landlord;
use App\Models\StaffUser;
use App\Models\User;

// --- Access Control ---

test('staff user can access landlords page', function () {
    $staffUser = StaffUser::factory()->create();

    $this->actingAs($staffUser, 'staff')
        ->get(route('staff.landlords.index'))
        ->assertOk();
});

test('guest cannot access landlords page', function () {
    $this->get(route('staff.landlords.index'))
        ->assertRedirect(route('staff.login'));
});

test('landlord-side user cannot access staff landlords page', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->get(route('staff.landlords.index'))
        ->assertRedirect(route('staff.login'));
});

// --- Cross-Landlord Visibility (Tier B, no scope) ---

test('staff user sees landlords from multiple organizations in one list', function () {
    $staffUser = StaffUser::factory()->create();
    Landlord::factory()->create(['name' => 'Acme Properties']);
    Landlord::factory()->create(['name' => 'Beta Estates']);

    $response = $this->actingAs($staffUser, 'staff')
        ->get(route('staff.landlords.index'));

    $response->assertOk();
    $response->assertSee('Acme Properties');
    $response->assertSee('Beta Estates');
});

// --- Create Landlord ---

test('staff user can create a landlord with an initial admin user', function () {
    $staffUser = StaffUser::factory()->create();

    $this->actingAs($staffUser, 'staff')
        ->post(route('staff.landlords.store'), [
            'company_name' => 'New Co Properties',
            'trading_name' => 'NewCo',
            'address' => 'Kampala',
            'admin_name' => 'Landlord Admin',
            'email' => 'landlordadmin@example.com',
            'phone' => '+256771234567',
            'password' => 'Secret-1234',
        ])->assertRedirect()->assertSessionHas('success');

    $this->assertDatabaseHas('landlords', [
        'name' => 'New Co Properties',
        'contact_email' => 'landlordadmin@example.com',
        'status' => 'active',
    ]);

    $landlord = Landlord::where('contact_email', 'landlordadmin@example.com')->first();

    $this->assertDatabaseHas('users', [
        'landlord_id' => $landlord->id,
        'email' => 'landlordadmin@example.com',
        'role' => 'admin',
        'must_change_password' => true,
    ]);
});

test('landlord contact email must be unique when creating landlord', function () {
    $staffUser = StaffUser::factory()->create();
    Landlord::factory()->create(['contact_email' => 'taken@example.com']);

    $this->actingAs($staffUser, 'staff')
        ->post(route('staff.landlords.store'), [
            'company_name' => 'Another Co',
            'admin_name' => 'Someone',
            'email' => 'taken@example.com',
            'phone' => '+256771111111',
            'password' => 'Secret-1234',
        ])->assertRedirect()->assertSessionHasErrors('email');
});

// --- Update Landlord ---

test('staff user can update a landlord', function () {
    $staffUser = StaffUser::factory()->create();
    $landlord = Landlord::factory()->create();

    $this->actingAs($staffUser, 'staff')
        ->put(route('staff.landlords.update', $landlord), [
            'company_name' => 'Renamed Co',
            'trading_name' => 'Renamed',
            'email' => $landlord->contact_email,
            'phone' => $landlord->contact_phone,
        ])->assertRedirect()->assertSessionHas('success');

    expect($landlord->fresh()->name)->toBe('Renamed Co');
});

// --- Suspend / Reactivate ---

test('staff user can suspend a landlord', function () {
    $staffUser = StaffUser::factory()->create();
    $landlord = Landlord::factory()->create();

    $this->actingAs($staffUser, 'staff')
        ->patch(route('staff.landlords.suspend', $landlord))
        ->assertRedirect()->assertSessionHas('success');

    expect($landlord->fresh()->status)->toBe('suspended');
});

test('staff user can reactivate a suspended landlord', function () {
    $staffUser = StaffUser::factory()->create();
    $landlord = Landlord::factory()->suspended()->create();

    $this->actingAs($staffUser, 'staff')
        ->patch(route('staff.landlords.reactivate', $landlord))
        ->assertRedirect()->assertSessionHas('success');

    expect($landlord->fresh()->status)->toBe('active');
});
