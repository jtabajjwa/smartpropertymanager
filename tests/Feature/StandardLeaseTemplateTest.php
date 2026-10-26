<?php

use App\Models\Estate;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\LeaseTemplate;
use App\Models\Location;
use App\Models\StaffUser;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\CurrentLandlord;
use App\Services\StandardLeaseTemplate;
use Illuminate\Support\Facades\Notification;

afterEach(fn () => CurrentLandlord::clear());

function templatesOf(int $landlordId)
{
    return LeaseTemplate::withoutGlobalScopes()->where('landlord_id', $landlordId)->get();
}

// --- Every landlord gets it ---

test('a landlord who signs up starts with the standard agreement as their default', function () {
    Notification::fake();

    $this->post(route('signup'), [
        'company_name' => 'Fresh Homes Ltd',
        'name' => 'Owner Person',
        'email' => 'owner@freshhomes.test',
        'phone' => '+256771000111',
        'password' => 'secret1234',
        'password_confirmation' => 'secret1234',
    ])->assertRedirect();

    $landlord = Landlord::where('name', 'Fresh Homes Ltd')->firstOrFail();
    $templates = templatesOf($landlord->id);

    expect($templates)->toHaveCount(1)
        ->and($templates->first()->name)->toBe(StandardLeaseTemplate::NAME)
        ->and($templates->first()->is_default)->toBeTrue();
});

test('a landlord created by staff also gets the standard agreement', function () {
    $staff = StaffUser::factory()->create();

    $this->actingAs($staff, 'staff')->post(route('staff.landlords.store'), [
        'company_name' => 'Staff Made Co',
        'admin_name' => 'Admin Person',
        'email' => 'admin@staffmade.test',
        'phone' => '+256771000222',
        'password' => 'secret1234',
    ])->assertSessionHas('success');

    $landlord = Landlord::where('name', 'Staff Made Co')->firstOrFail();

    expect(templatesOf($landlord->id)->pluck('name')->all())->toBe([StandardLeaseTemplate::NAME]);
});

test('installing is safe to repeat and never steals an existing default', function () {
    $landlord = Landlord::factory()->create();
    CurrentLandlord::set($landlord->id);
    LeaseTemplate::create(['name' => 'My Own Agreement', 'content' => 'Mine.', 'is_default' => true]);
    CurrentLandlord::clear();

    $installer = app(StandardLeaseTemplate::class);
    $installer->installFor($landlord->id);
    $installer->installFor($landlord->id);

    $templates = templatesOf($landlord->id);
    expect($templates)->toHaveCount(2)
        ->and($templates->firstWhere('name', 'My Own Agreement')->is_default)->toBeTrue()
        ->and($templates->firstWhere('name', StandardLeaseTemplate::NAME)->is_default)->toBeFalse();
});

test('the add standard agreement button adds a fresh copy alongside an edited one', function () {
    $user = User::factory()->create();
    app(StandardLeaseTemplate::class)->installFor($user->landlord_id);

    $this->actingAs($user)->post(route('lease-templates.standard'))->assertSessionHas('success');

    $names = templatesOf($user->landlord_id)->pluck('name');
    expect($names)->toHaveCount(2)
        ->and($names->filter(fn ($n) => str_starts_with($n, StandardLeaseTemplate::NAME.' (fresh copy'))->count())->toBe(1);
});

// --- Content ---

test('every placeholder in the standard agreement is one the app knows how to fill', function () {
    preg_match_all('/\{\{(\w+)\}\}/', StandardLeaseTemplate::content(), $matches);
    $used = array_unique($matches[1]);

    expect($used)->not->toBeEmpty()
        ->and(array_diff($used, array_keys(LeaseTemplate::TOKENS)))->toBe([]);
});

test('the standard agreement is filled in from the lease and renders as a PDF', function () {
    $user = User::factory()->create();
    $landlord = Landlord::find($user->landlord_id);
    $landlord->update(['name' => 'Kololo Homes Ltd', 'address' => 'Plot 12 Acacia Avenue, Kampala']);
    $template = app(StandardLeaseTemplate::class)->installFor($landlord->id);

    CurrentLandlord::set($landlord->id);
    $location = Location::create(['name' => 'Kololo', 'country' => 'Uganda', 'district' => 'Kampala', 'address' => 'Acacia Avenue']);
    $estate = Estate::create(['name' => 'Acacia Courts', 'location_id' => $location->id]);
    $unit = Unit::create(['name' => 'B4', 'estate_id' => $estate->id, 'status' => 'vacant']);
    $tenant = Tenant::create([
        'type' => 'individual', 'name' => 'Sarah Nakato', 'email' => 'sarah@example.test', 'primary_phone' => '+256772000333',
        'id_type' => 'national_id', 'id_number' => 'CM9001234567AB',
        'emergency_contact_name' => 'Kin', 'emergency_contact_relationship' => 'family', 'emergency_contact_phone' => '+256700000000',
    ]);
    $lease = Lease::create([
        'number' => 'LSE-00042', 'tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'status' => 'active',
        'start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'rent_amount' => 850000, 'billing_cycle' => 'monthly',
        'security_deposit' => 850000, 'required_upfront_periods' => 3, 'next_invoice_date' => '2027-02-01',
    ]);

    $text = $template->renderFor($lease->load(['tenant', 'unit.estate.location', 'landlord']));
    CurrentLandlord::clear();

    expect($text)
        ->toContain('Kololo Homes Ltd of Plot 12 Acacia Avenue, Kampala')
        ->toContain('Sarah Nakato, holder of National ID number CM9001234567AB')
        ->toContain('B4 in Acacia Courts, Kololo, Acacia Avenue, Kampala')
        ->toContain('begins on 01 Nov 2026. Its end date is: 31 Oct 2027.')
        ->toContain('The rent is UGX 850,000.00 per rent period (billing cycle: Monthly)')
        ->toContain('rent for the first 3 months in advance')
        ->toContain('security deposit of UGX 850,000.00')
        ->not->toContain('{{');

    $this->actingAs($user)
        ->get(route('leases.agreement', ['lease' => $lease->id, 'template_id' => $template->id]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('details the landlord has not entered print as blank lines to fill in by hand', function () {
    $template = new LeaseTemplate(['content' => '{{landlord_name}} of {{landlord_address}}, ID {{tenant_id_number}}']);
    $landlord = Landlord::factory()->create(['name' => 'No Address Co', 'address' => null]);
    $lease = new Lease();
    $lease->setRelation('landlord', $landlord);
    $lease->setRelation('tenant', null);
    $lease->setRelation('unit', null);

    expect($template->renderFor($lease))->toBe('No Address Co of ____________________, ID ____________________');
});
