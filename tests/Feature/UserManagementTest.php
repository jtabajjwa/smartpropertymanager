<?php

use App\Models\User;

// --- Access Control ---

test('admin can access users page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('users.index'))
        ->assertOk();
});

test('non-admin cannot access users page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('users.index'))
        ->assertForbidden();
});

test('guest cannot access users page', function () {
    $this->get(route('users.index'))
        ->assertRedirect(route('login'));
});

// --- Create User ---

test('admin can create a user', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'name' => 'New User',
            'email' => 'new@example.com',
            'phone' => '+256771234567',
            'password' => 'secret1234',
            'role' => 'user',
        ])->assertRedirect()->assertSessionHas('success');

    $this->assertDatabaseHas('users', [
        'email' => 'new@example.com',
        'name' => 'New User',
        'must_change_password' => true,
    ]);
});

test('created user must change password on first login', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'name' => 'Fresh User',
            'email' => 'fresh@example.com',
            'password' => 'secret1234',
            'role' => 'user',
        ]);

    $newUser = User::where('email', 'fresh@example.com')->first();
    expect($newUser->must_change_password)->toBeTrue();
});

test('email must be unique when creating user', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'name' => 'Another User',
            'email' => 'taken@example.com',
            'password' => 'secret1234',
            'role' => 'user',
        ])->assertRedirect()->assertSessionHasErrors('email');
});

test('phone must be unique when creating user', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['phone' => '+256771111111']);

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'name' => 'New User',
            'email' => 'newphone@example.com',
            'phone' => '+256771111111',
            'password' => 'secret1234',
            'role' => 'user',
        ])->assertRedirect()->assertSessionHasErrors('phone');
});

// --- Update User ---

test('admin can update a user', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->put(route('users.update', $user), [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
            'role' => 'user',
        ])->assertRedirect()->assertSessionHas('success');

    expect($user->fresh()->name)->toBe('Updated Name');
    expect($user->fresh()->email)->toBe('updated@example.com');
});

test('non-admin cannot update a user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)
        ->put(route('users.update', $other), [
            'name' => 'Hacked',
            'email' => $other->email,
            'role' => 'admin',
        ])->assertForbidden();
});

// --- Toggle Active ---

test('admin can deactivate a user', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->patch(route('users.toggle-active', $user))
        ->assertRedirect()->assertSessionHas('success');

    expect($user->fresh()->is_active)->toBeFalse();
});

test('admin can activate an inactive user', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->inactive()->create();

    $this->actingAs($admin)
        ->patch(route('users.toggle-active', $user))
        ->assertRedirect()->assertSessionHas('success');

    expect($user->fresh()->is_active)->toBeTrue();
});

// --- Last Admin Protection ---

test('cannot change the only admin role to user', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'user',
        ])->assertRedirect()->assertSessionHas('error');

    expect($admin->fresh()->role)->toBe('admin');
});

test('cannot deactivate the only admin', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patch(route('users.toggle-active', $admin))
        ->assertRedirect()->assertSessionHas('error');

    expect($admin->fresh()->is_active)->toBeTrue();
});

test('can change admin role when another admin exists', function () {
    $landlord = \App\Models\Landlord::factory()->create();
    $admin1 = User::factory()->admin()->create(['landlord_id' => $landlord->id]);
    $admin2 = User::factory()->admin()->create(['landlord_id' => $landlord->id]);

    $this->actingAs($admin1)
        ->put(route('users.update', $admin2), [
            'name' => $admin2->name,
            'email' => $admin2->email,
            'role' => 'user',
        ])->assertRedirect()->assertSessionHas('success');

    expect($admin2->fresh()->role)->toBe('user');
});

test('can deactivate an admin when another admin exists', function () {
    $landlord = \App\Models\Landlord::factory()->create();
    $admin1 = User::factory()->admin()->create(['landlord_id' => $landlord->id]);
    $admin2 = User::factory()->admin()->create(['landlord_id' => $landlord->id]);

    $this->actingAs($admin1)
        ->patch(route('users.toggle-active', $admin2))
        ->assertRedirect()->assertSessionHas('success');

    expect($admin2->fresh()->is_active)->toBeFalse();
});
