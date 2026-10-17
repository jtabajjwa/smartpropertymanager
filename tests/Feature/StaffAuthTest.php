<?php

use App\Models\StaffUser;
use App\Models\User;

// --- Login ---

test('staff login page is accessible to guests', function () {
    $this->get(route('staff.login'))->assertOk();
});

test('staff user can login with valid credentials', function () {
    $staffUser = StaffUser::factory()->create(['email' => 'teststaff@example.com']);

    $this->post(route('staff.login'), [
        'email' => 'teststaff@example.com',
        'password' => 'password',
    ])->assertRedirect(route('staff.dashboard'));

    $this->assertAuthenticatedAs($staffUser, 'staff');
});

test('staff login fails with invalid credentials', function () {
    StaffUser::factory()->create(['email' => 'teststaff@example.com']);

    $this->post(route('staff.login'), [
        'email' => 'teststaff@example.com',
        'password' => 'wrong-password',
    ])->assertRedirect()->assertSessionHas('error');

    $this->assertGuest('staff');
});

test('inactive staff user cannot login', function () {
    StaffUser::factory()->inactive()->create(['email' => 'blocked@example.com']);

    $this->post(route('staff.login'), [
        'email' => 'blocked@example.com',
        'password' => 'password',
    ])->assertRedirect()->assertSessionHas('error');

    $this->assertGuest('staff');
});

test('staff user with must_change_password is redirected to change password', function () {
    StaffUser::factory()->mustChangePassword()->create(['email' => 'newstaff@example.com']);

    $this->post(route('staff.login'), [
        'email' => 'newstaff@example.com',
        'password' => 'password',
    ])->assertRedirect(route('staff.change-password'));
});

test('authenticated staff user is redirected away from staff login page', function () {
    $staffUser = StaffUser::factory()->create();

    $this->actingAs($staffUser, 'staff')
        ->get(route('staff.login'))
        ->assertRedirect(route('staff.dashboard'));
});

// --- Logout ---

test('staff user can logout', function () {
    $staffUser = StaffUser::factory()->create();

    $this->actingAs($staffUser, 'staff')
        ->post(route('staff.logout'))
        ->assertRedirect(route('staff.login'));

    $this->assertGuest('staff');
});

// --- Change Password ---

test('staff user can change password with correct current password', function () {
    $staffUser = StaffUser::factory()->mustChangePassword()->create();

    $this->actingAs($staffUser, 'staff')
        ->post(route('staff.change-password'), [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect(route('staff.dashboard'));

    expect($staffUser->fresh()->must_change_password)->toBeFalse();
});

test('staff change password fails with wrong current password', function () {
    $staffUser = StaffUser::factory()->create();

    $this->actingAs($staffUser, 'staff')
        ->post(route('staff.change-password'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect()->assertSessionHasErrors('current_password');
});

// --- Must Change Password Middleware ---

test('staff user with must_change_password cannot access dashboard', function () {
    $staffUser = StaffUser::factory()->mustChangePassword()->create();

    $this->actingAs($staffUser, 'staff')
        ->get(route('staff.dashboard'))
        ->assertRedirect(route('staff.change-password'));
});

// --- Check Active Middleware ---

test('deactivated staff user is logged out on next request', function () {
    $staffUser = StaffUser::factory()->create();

    $this->actingAs($staffUser, 'staff');

    $staffUser->update(['is_active' => false]);

    $this->get(route('staff.dashboard'))
        ->assertRedirect(route('staff.login'));

    $this->assertGuest('staff');
});

// --- Guest Protection ---

test('unauthenticated staff request is redirected to staff login', function () {
    $this->get(route('staff.dashboard'))
        ->assertRedirect(route('staff.login'));
});

// --- Guard Isolation ---

test('staff session does not authenticate the landlord web guard', function () {
    $staffUser = StaffUser::factory()->create();

    $this->actingAs($staffUser, 'staff')
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

test('landlord session does not authenticate the staff guard', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->get(route('staff.dashboard'))
        ->assertRedirect(route('staff.login'));
});

// --- Forgot / Reset Password ---

test('staff forgot password sends a reset code email to a valid staff user', function () {
    \Illuminate\Support\Facades\Mail::fake();

    $staffUser = StaffUser::factory()->create(['email' => 'staffreset@example.com']);

    $this->post(route('staff.forgot-password'), ['email' => 'staffreset@example.com'])
        ->assertRedirect(route('staff.reset-password', ['email' => 'staffreset@example.com']))
        ->assertSessionHas('success');

    \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\PasswordResetCodeMail::class, function ($mail) use ($staffUser) {
        return $mail->hasTo($staffUser->email);
    });
});

test('staff user can reset password with a valid emailed code', function () {
    $staffUser = StaffUser::factory()->create(['email' => 'staffreset@example.com']);

    \App\Models\StaffPasswordResetCode::create([
        'staff_user_id' => $staffUser->id,
        'code' => '123456',
        'expires_at' => now()->addMinutes(10),
    ]);

    $this->post(route('staff.reset-password'), [
        'email' => 'staffreset@example.com',
        'code' => '123456',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertRedirect(route('staff.login'))->assertSessionHas('success');

    expect(\Illuminate\Support\Facades\Hash::check('new-password-123', $staffUser->fresh()->password))->toBeTrue();
});
