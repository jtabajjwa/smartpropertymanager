<?php

use App\Models\Estate;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\LeaseTemplate;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\CurrentLandlord;

function makeLeaseForLandlord(int $landlordId): Lease
{
    CurrentLandlord::set($landlordId);


    $estate = Estate::create([
        'name' => 'Sunset Gardens',
        'district' => 'Kampala',
    ]);

    $unit = Unit::create([
        'name' => 'Unit A1',
        'estate_id' => $estate->id,
        'status' => 'occupied',
    ]);

    $tenant = Tenant::create([
        'type' => 'individual',
        'name' => 'Jane Doe',
        'email' => fake()->unique()->safeEmail(),
        'primary_phone' => fake()->unique()->e164PhoneNumber(),
        'id_type' => 'national_id',
        'id_number' => 'NIN-' . fake()->unique()->randomNumber(8),
        'emergency_contact_name' => 'Emergency Contact',
        'emergency_contact_relationship' => 'family',
        'emergency_contact_phone' => '+256700000000',
    ]);

    $lease = Lease::create([
        'tenant_id' => $tenant->id,
        'unit_id' => $unit->id,
        'status' => 'active',
        'start_date' => '2026-01-01',
        'rent_amount' => 500000,
        'billing_cycle' => 'monthly',
        'security_deposit' => 500000,
        'required_upfront_periods' => 1,
        'next_invoice_date' => today()->format('Y-m-d'),
        'number' => 'LSE-00001',
    ]);

    CurrentLandlord::clear();

    return $lease;
}

// --- Access Control ---

test('user can access lease templates page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('lease-templates.index'))
        ->assertOk();
});

test('guest cannot access lease templates page', function () {
    $this->get(route('lease-templates.index'))
        ->assertRedirect(route('login'));
});

// --- Create Template ---

test('user can create a lease template', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('lease-templates.store'), [
            'name' => 'Standard Residential',
            'content' => 'Agreement for {{tenant_name}} at {{unit_name}}.',
        ])->assertRedirect()->assertSessionHas('success');

    $this->assertDatabaseHas('lease_templates', [
        'landlord_id' => $user->landlord_id,
        'name' => 'Standard Residential',
    ]);
});

test('lease template name and content are required', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('lease-templates.store'), [])
        ->assertRedirect()
        ->assertSessionHasErrors(['name', 'content']);
});

test('marking a template as default unmarks the previous default', function () {
    $user = User::factory()->create();
    $first = LeaseTemplate::factory()->create(['landlord_id' => $user->landlord_id, 'is_default' => true]);

    $this->actingAs($user)
        ->post(route('lease-templates.store'), [
            'name' => 'New Default',
            'content' => 'Content here.',
            'is_default' => '1',
        ])->assertRedirect()->assertSessionHas('success');

    expect($first->fresh()->is_default)->toBeFalse();
    expect(LeaseTemplate::where('name', 'New Default')->first()->is_default)->toBeTrue();
});

// --- Update / Delete ---

test('user can update a lease template', function () {
    $user = User::factory()->create();
    $template = LeaseTemplate::factory()->create(['landlord_id' => $user->landlord_id]);

    $this->actingAs($user)
        ->put(route('lease-templates.update', $template), [
            'name' => 'Updated Name',
            'content' => $template->content,
        ])->assertRedirect()->assertSessionHas('success');

    expect($template->fresh()->name)->toBe('Updated Name');
});

test('user can delete a lease template', function () {
    $user = User::factory()->create();
    $template = LeaseTemplate::factory()->create(['landlord_id' => $user->landlord_id]);

    $this->actingAs($user)
        ->delete(route('lease-templates.destroy', $template))
        ->assertRedirect()->assertSessionHas('success');

    $this->assertDatabaseMissing('lease_templates', ['id' => $template->id]);
});

// --- Token rendering ---

test('renderFor replaces all tokens with lease data', function () {
    $landlord = Landlord::factory()->create(['name' => 'Acme Properties']);
    $lease = makeLeaseForLandlord($landlord->id);

    $template = new LeaseTemplate([
        'content' => 'Landlord: {{landlord_name}}, Tenant: {{tenant_name}}, Unit: {{unit_name}} at {{estate_name}}, Rent: {{rent_amount}}, Cycle: {{billing_cycle}}, Lease#: {{lease_number}}',
    ]);

    CurrentLandlord::set($landlord->id);
    $lease->load(['tenant', 'unit.estate', 'landlord']);
    CurrentLandlord::clear();

    $rendered = $template->renderFor($lease);

    expect($rendered)->toContain('Acme Properties');
    expect($rendered)->toContain('Jane Doe');
    expect($rendered)->toContain('Unit A1');
    expect($rendered)->toContain('Sunset Gardens');
    expect($rendered)->toContain('UGX 500,000.00');
    expect($rendered)->toContain('Monthly');
    expect($rendered)->toContain('LSE-00001');
    expect($rendered)->not->toContain('{{');
});

// --- Generate Agreement PDF ---

test('user can generate an agreement pdf for a lease', function () {
    $user = User::factory()->create();
    $lease = makeLeaseForLandlord($user->landlord_id);
    $template = LeaseTemplate::factory()->create(['landlord_id' => $user->landlord_id]);

    $response = $this->actingAs($user)
        ->get(route('leases.agreement', $lease) . '?template_id=' . $template->id);

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
});

test('cannot generate an agreement using another landlord\'s template', function () {
    $user = User::factory()->create();
    $lease = makeLeaseForLandlord($user->landlord_id);

    $otherLandlord = Landlord::factory()->create();
    $otherTemplate = LeaseTemplate::factory()->create(['landlord_id' => $otherLandlord->id]);

    $this->actingAs($user)
        ->get(route('leases.agreement', $lease) . '?template_id=' . $otherTemplate->id)
        ->assertNotFound();
});

test('cannot generate an agreement for another landlord\'s lease', function () {
    $user = User::factory()->create();
    $template = LeaseTemplate::factory()->create(['landlord_id' => $user->landlord_id]);

    $otherLandlord = Landlord::factory()->create();
    $otherLease = makeLeaseForLandlord($otherLandlord->id);

    $this->actingAs($user)
        ->get(route('leases.agreement', $otherLease) . '?template_id=' . $template->id)
        ->assertNotFound();
});

// --- Landlord Isolation ---

test('lease templates index only shows the current landlord\'s templates', function () {
    $landlordA = Landlord::factory()->create();
    $landlordB = Landlord::factory()->create();

    CurrentLandlord::set($landlordA->id);
    LeaseTemplate::create(['name' => 'Template A', 'content' => 'A']);
    CurrentLandlord::clear();

    CurrentLandlord::set($landlordB->id);
    LeaseTemplate::create(['name' => 'Template B', 'content' => 'B']);
    CurrentLandlord::clear();

    $userA = User::factory()->create(['landlord_id' => $landlordA->id]);

    $response = $this->actingAs($userA)->get(route('lease-templates.index'));

    $response->assertOk();
    $response->assertSee('Template A');
    $response->assertDontSee('Template B');
});
