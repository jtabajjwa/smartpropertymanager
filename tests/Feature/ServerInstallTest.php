<?php

use App\Models\Landlord;
use App\Models\StaffUser;
use Illuminate\Support\Facades\Hash;

/*
 * What a fresh server install relies on (see deploy/README.md).
 */

test('a fresh database has no placeholder landlord', function () {
    expect(Landlord::count())->toBe(0);
});

test('staff:create-admin creates a staff admin', function () {
    $this->artisan('staff:create-admin', ['--name' => 'Ops Admin', '--email' => 'Ops@Example.test', '--phone' => '+256700111222'])
        ->expectsQuestion('Password (at least 10 characters)', 'a-long-password')
        ->expectsQuestion('Password again', 'a-long-password')
        ->assertSuccessful();

    $staff = StaffUser::where('email', 'ops@example.test')->firstOrFail();
    expect($staff->isAdmin())->toBeTrue()
        ->and($staff->must_change_password)->toBeFalse()
        ->and(Hash::check('a-long-password', $staff->password))->toBeTrue();
});

test('staff:create-admin refuses a short or mismatched password and a taken email', function () {
    $this->artisan('staff:create-admin', ['--name' => 'A', '--email' => 'a@example.test'])
        ->expectsQuestion('Phone (optional)', '')
        ->expectsQuestion('Password (at least 10 characters)', 'short')
        ->expectsQuestion('Password again', 'short')
        ->assertFailed();

    StaffUser::factory()->create(['email' => 'taken@example.test']);
    $this->artisan('staff:create-admin', ['--name' => 'B', '--email' => 'taken@example.test', '--phone' => ''])
        ->expectsQuestion('Phone (optional)', '')
        ->expectsQuestion('Password (at least 10 characters)', 'a-long-password')
        ->expectsQuestion('Password again', 'a-long-password-2')
        ->assertFailed();

    expect(StaffUser::count())->toBe(1);
});
