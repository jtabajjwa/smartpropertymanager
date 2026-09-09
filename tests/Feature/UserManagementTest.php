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
            'username' => 'newuser',
            'name' => 'New User',
            'email' => 'new@example.com',
            'phone' => '+256771234567',
            'password' => 'secret1234',
            'role' => 'user',
        ])->assertRedirect()->assertSessionHas('success');

    $this->assertDatabaseHas('users', [
        'username' => 'newuser',
        'name' => 'New User',
        'must_change_password' => true,
    ]);
});

test('created user must change password on first login', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'username' => 'freshuser',
            'name' => 'Fresh User',
            'password' => 'secret1234',
            'role' => 'user',
        ]);

    $newUser = User::where('username', 'freshuser')->first();
    expect($newUser->must_change_password)->toBeTrue();
});

test('username must be unique when creating user', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['username' => 'taken']);

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'username' => 'taken',
            'name' => 'Another User',
            'password' => 'secret1234',
            'role' => 'user',
        ])->assertRedirect()->assertSessionHasErrors('username');
});

test('phone must be unique when creating user', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['phone' => '+256771111111']);

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'username' => 'newuser',
            'name' => 'New User',
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
            'username' => 'updated-username',
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
            'role' => 'user',
        ])->assertRedirect()->assertSessionHas('success');

    expect($user->fresh()->name)->toBe('Updated Name');
    expect($user->fresh()->username)->toBe('updated-username');
});

test('non-admin cannot update a user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)
        ->put(route('users.update', $other), [
            'username' => 'hacked',
            'name' => 'Hacked',
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
            'username' => $admin->username,
            'name' => $admin->name,
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
    $admin1 = User::factory()->admin()->create();
    $admin2 = User::factory()->admin()->create();

    $this->actingAs($admin1)
        ->put(route('users.update', $admin2), [
            'username' => $admin2->username,
            'name' => $admin2->name,
            'role' => 'user',
        ])->assertRedirect()->assertSessionHas('success');

    expect($admin2->fresh()->role)->toBe('user');
});

test('can deactivate an admin when another admin exists', function () {
    $admin1 = User::factory()->admin()->create();
    $admin2 = User::factory()->admin()->create();

    $this->actingAs($admin1)
        ->patch(route('users.toggle-active', $admin2))
        ->assertRedirect()->assertSessionHas('success');

    expect($admin2->fresh()->is_active)->toBeFalse();
});
