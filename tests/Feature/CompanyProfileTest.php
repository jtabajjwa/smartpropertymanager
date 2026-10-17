<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// --- Access Control ---

test('admin can access company profile page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('company-profile.edit'))
        ->assertOk();
});

test('non-admin cannot access company profile page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('company-profile.edit'))
        ->assertForbidden();
});

test('guest cannot access company profile page', function () {
    $this->get(route('company-profile.edit'))
        ->assertRedirect(route('login'));
});

// --- Update ---

test('admin can update company profile details', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('company-profile.update'), [
            'name' => 'Renamed Properties Ltd',
            'trading_name' => 'Renamed Props',
            'address' => '456 New Road',
            'brand_color' => '#2a78d6',
            'invoice_footer_note' => 'Bank: Example Bank, Acct: 0001112223',
        ])->assertRedirect()->assertSessionHas('success');

    $landlord = $admin->landlord;
    expect($landlord->fresh()->name)->toBe('Renamed Properties Ltd');
    expect($landlord->fresh()->brand_color)->toBe('#2a78d6');
    expect($landlord->fresh()->invoice_footer_note)->toBe('Bank: Example Bank, Acct: 0001112223');
});

test('brand color must be a valid hex code', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('company-profile.update'), [
            'name' => 'Test Co',
            'brand_color' => 'not-a-color',
        ])->assertRedirect()->assertSessionHasErrors('brand_color');
});

test('non-admin cannot update company profile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('company-profile.update'), [
            'name' => 'Hacked Name',
            'brand_color' => '#000000',
        ])->assertForbidden();

    expect($user->landlord->fresh()->name)->not->toBe('Hacked Name');
});

// --- Logo Upload ---

test('admin can upload a logo', function () {
    Storage::fake('public');

    $admin = User::factory()->admin()->create();
    $logo = UploadedFile::fake()->image('logo.png', 200, 80);

    $this->actingAs($admin)
        ->put(route('company-profile.update'), [
            'name' => $admin->landlord->name,
            'brand_color' => '#c1704a',
            'logo' => $logo,
        ])->assertRedirect()->assertSessionHas('success');

    $landlord = $admin->landlord->fresh();
    expect($landlord->logo)->not->toBeNull();
    Storage::disk('public')->assertExists($landlord->logo);
});

test('uploading a new logo deletes the old one', function () {
    Storage::fake('public');

    $admin = User::factory()->admin()->create();
    $firstLogo = UploadedFile::fake()->image('first.png');

    $this->actingAs($admin)->put(route('company-profile.update'), [
        'name' => $admin->landlord->name,
        'brand_color' => '#c1704a',
        'logo' => $firstLogo,
    ]);

    $oldPath = $admin->landlord->fresh()->logo;
    Storage::disk('public')->assertExists($oldPath);

    $secondLogo = UploadedFile::fake()->image('second.png');
    $this->actingAs($admin)->put(route('company-profile.update'), [
        'name' => $admin->landlord->name,
        'brand_color' => '#c1704a',
        'logo' => $secondLogo,
    ]);

    Storage::disk('public')->assertMissing($oldPath);
    expect($admin->landlord->fresh()->logo)->not->toBe($oldPath);
});

test('logo must be an image file', function () {
    Storage::fake('public');

    $admin = User::factory()->admin()->create();
    $file = UploadedFile::fake()->create('document.pdf', 100);

    $this->actingAs($admin)
        ->put(route('company-profile.update'), [
            'name' => $admin->landlord->name,
            'brand_color' => '#c1704a',
            'logo' => $file,
        ])->assertRedirect()->assertSessionHasErrors('logo');
});
