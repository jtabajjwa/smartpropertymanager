<?php

use App\Models\User;

// --- Login ---

test('login page is accessible to guests', function () {
    $this->get(route('login'))->assertOk();
});

test('user can login with valid credentials', function () {
    $user = User::factory()->create(['username' => 'testuser']);

    $this->post(route('login'), [
        'username' => 'testuser',
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('login fails with invalid credentials', function () {
    User::factory()->create(['username' => 'testuser']);

    $this->post(route('login'), [
        'username' => 'testuser',
        'password' => 'wrong-password',
    ])->assertRedirect()->assertSessionHas('error');

    $this->assertGuest();
});

test('inactive user cannot login', function () {
    User::factory()->inactive()->create(['username' => 'blocked']);

    $this->post(route('login'), [
        'username' => 'blocked',
        'password' => 'password',
    ])->assertRedirect()->assertSessionHas('error');

    $this->assertGuest();
});

test('user with must_change_password is redirected to change password', function () {
    User::factory()->mustChangePassword()->create(['username' => 'newuser']);

    $this->post(route('login'), [
        'username' => 'newuser',
        'password' => 'password',
    ])->assertRedirect(route('change-password'));
});

test('authenticated user is redirected away from login page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('login'))
        ->assertRedirect();
});

// --- Logout ---

test('user can logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

// --- Change Password ---

test('change password page is accessible to authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('change-password'))
        ->assertOk();
});

test('user can change password with correct current password', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)
        ->post(route('change-password'), [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect(route('dashboard'));

    // Verify must_change_password is now false
    expect($user->fresh()->must_change_password)->toBeFalse();
});

test('change password fails with wrong current password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('change-password'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect()->assertSessionHasErrors('current_password');
});

test('new password must be different from current password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('change-password'), [
            'current_password' => 'password',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect()->assertSessionHasErrors('password');
});

test('new password must be confirmed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('change-password'), [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'different-password',
        ])->assertRedirect()->assertSessionHasErrors('password');
});

test('new password must be at least 8 characters', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('change-password'), [
            'current_password' => 'password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertRedirect()->assertSessionHasErrors('password');
});

// --- Must Change Password Middleware ---

test('user with must_change_password cannot access dashboard', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('change-password'));
});

test('user with must_change_password can still logout', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

// --- Check Active Middleware ---

test('deactivated user is logged out on next request', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    // Deactivate user after login
    $user->update(['is_active' => false]);

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

// --- Guest Protection ---

test('unauthenticated user is redirected to login', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

// --- Forgot / Reset Password ---

test('forgot password page is accessible', function () {
    $this->get(route('forgot-password'))->assertOk();
});

test('reset password page is accessible', function () {
    $this->get(route('reset-password'))->assertOk();
});
