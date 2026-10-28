<?php

use App\Models\Estate;
use App\Models\Issue;
use App\Models\Landlord;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorJob;
use App\Services\CurrentLandlord;

afterEach(fn () => CurrentLandlord::clear());

/** A landlord admin, a tenant's open issue in unit B2 of Acacia Court, and a vendor. */
function issueSetup(): array
{
    $user = User::factory()->admin()->create();
    CurrentLandlord::set($user->landlord_id);
    $estate = Estate::create(['name' => 'Acacia Court', 'district' => 'Kampala']);
    $unit = Unit::create(['name' => 'B2', 'estate_id' => $estate->id, 'status' => 'occupied']);
    $tenant = Tenant::create([
        'type' => 'individual', 'name' => 'Grace Namata', 'email' => 'grace@example.test', 'primary_phone' => '+256772123456',
        'id_type' => 'national_id', 'id_number' => 'NIN-123',
        'emergency_contact_name' => 'Kin', 'emergency_contact_relationship' => 'family', 'emergency_contact_phone' => '+256700000000',
    ]);
    $issue = Issue::create([
        'number' => 'ISS-00001', 'tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'category' => 'repair',
        'title' => 'Kitchen tap leaking', 'description' => 'Water drips all night.', 'status' => 'open',
    ]);
    $vendor = Vendor::create(['name' => 'Kato Plumbers', 'category' => 'plumber']);
    CurrentLandlord::clear();

    return compact('user', 'estate', 'unit', 'issue', 'vendor');
}

function issueJob(array $s, array $extra = []): array
{
    return array_merge([
        'vendor_id' => $s['vendor']->id, 'issue_id' => $s['issue']->id, 'unit_id' => $s['unit']->id, 'estate_id' => $s['estate']->id,
        'title' => 'Fix kitchen tap', 'job_date' => '2026-11-03', 'status' => 'scheduled', 'cost' => 60000,
    ], $extra);
}

function issueNotes(Issue $issue): array
{
    return CurrentLandlord::runAs($issue->landlord_id, fn () => $issue->updates()->oldest('id')->pluck('note')->filter()->values()->all());
}

test('booking a job for an issue tells the tenant the date and moves the issue to in progress', function () {
    $s = issueSetup();

    $this->actingAs($s['user'])->post(route('vendor-jobs.store'), issueJob($s))->assertSessionHasNoErrors();

    $issue = CurrentLandlord::runAs($s['user']->landlord_id, fn () => $s['issue']->fresh());
    expect($issue->status)->toBe('in_progress')
        ->and(issueNotes($issue))->toBe(['Repair booked for 3 Nov 2026.']);
});

test('the vendor is named only when the landlord chooses to share it', function () {
    $s = issueSetup();

    $this->actingAs($s['user'])->post(route('vendor-jobs.store'), issueJob($s, ['share_vendor' => 1]));

    expect(issueNotes($s['issue']))->toBe(['Repair booked for 3 Nov 2026. Kato Plumbers will do the work.']);
});

test('the tenant hears when the repair moves, is cancelled, or is done', function () {
    $s = issueSetup();
    $this->actingAs($s['user'])->post(route('vendor-jobs.store'), issueJob($s));
    $job = CurrentLandlord::runAs($s['user']->landlord_id, fn () => VendorJob::first());

    $this->actingAs($s['user'])->put(route('vendor-jobs.update', $job), issueJob($s, ['job_date' => '2026-11-05']))->assertSessionHasNoErrors();
    $this->actingAs($s['user'])->put(route('vendor-jobs.update', $job), issueJob($s, ['job_date' => '2026-11-05', 'status' => 'cancelled']))->assertSessionHasNoErrors();
    $this->actingAs($s['user'])->put(route('vendor-jobs.update', $job), issueJob($s, ['job_date' => '2026-11-06', 'status' => 'scheduled']))->assertSessionHasNoErrors();
    $this->actingAs($s['user'])->put(route('vendor-jobs.update', $job), issueJob($s, ['job_date' => '2026-11-06', 'status' => 'completed', 'record' => 'none', 'share_vendor' => 1]))
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'Every job card for issue ISS-00001 is completed'));

    expect(issueNotes($s['issue']))->toBe([
        'Repair booked for 3 Nov 2026.',
        'The repair has moved to 5 Nov 2026.',
        'The repair booked for 5 Nov 2026 was cancelled.',
        'The repair has moved to 6 Nov 2026.',
        'Repair completed by Kato Plumbers on '.today()->format('j M Y').'.',
    ]);
});

test('completing the jobs prompts the landlord to resolve, but never resolves on its own', function () {
    $s = issueSetup();
    $this->actingAs($s['user'])->post(route('vendor-jobs.store'), issueJob($s, ['status' => 'completed', 'record' => 'none']));
    $this->actingAs($s['user'])->post(route('vendor-jobs.store'), issueJob($s, ['title' => 'Second visit', 'status' => 'cancelled']));

    $issue = CurrentLandlord::runAs($s['user']->landlord_id, fn () => $s['issue']->fresh());
    expect($issue->status)->toBe('in_progress');

    $this->actingAs($s['user'])->get(route('issues.show', $issue))->assertOk()
        ->assertSee('All job cards are completed.')->assertSee('Kato Plumbers')->assertSee('UGX 60,000');

    $this->actingAs($s['user'])->put(route('issues.update', $issue), ['status' => 'resolved', 'note' => 'The repair work is complete.'])->assertSessionHas('success');
    expect(CurrentLandlord::runAs($s['user']->landlord_id, fn () => $issue->fresh()->status))->toBe('resolved');
});

test('creating a job card from an issue fills in the form and comes back to the issue', function () {
    $s = issueSetup();

    $this->actingAs($s['user'])->get(route('issues.show', $s['issue']))->assertOk()->assertSee('Create job card');
    $this->actingAs($s['user'])->get(route('vendor-jobs.index', ['issue' => $s['issue']->id]))->assertOk()
        ->assertSee('Kitchen tap leaking')->assertSee('ISS-00001');

    $this->actingAs($s['user'])->post(route('vendor-jobs.store'), issueJob($s, ['from_issue' => 1]))
        ->assertRedirect(route('issues.show', $s['issue']));
    $this->actingAs($s['user'])->get(route('vendor-jobs.index'))->assertSee('Issue ISS-00001');
});

test('a job can only be linked to the landlord\'s own issues, and the picker lists open ones', function () {
    $s = issueSetup();
    $other = issueSetup();

    $this->actingAs($s['user'])->post(route('vendor-jobs.store'), issueJob($s, ['issue_id' => $other['issue']->id]))->assertSessionHasErrors('issue_id');

    CurrentLandlord::runAs($s['user']->landlord_id, fn () => Issue::create([
        'number' => 'ISS-00002', 'tenant_id' => $s['issue']->tenant_id, 'unit_id' => $s['unit']->id, 'category' => 'repair',
        'title' => 'Old closed issue', 'description' => 'Done.', 'status' => 'closed',
    ]));
    $this->actingAs($s['user'])->getJson(route('search.issues'))->assertOk()
        ->assertJsonCount(1)->assertJsonPath('0.text', 'ISS-00001 — Kitchen tap leaking (Unit B2)');
});

test('the tenant sees the booking in their issue history', function () {
    $s = issueSetup();
    $this->actingAs($s['user'])->post(route('vendor-jobs.store'), issueJob($s));

    $tenantId = $s['issue']->tenant_id;
    $this->withSession([\App\Http\Middleware\AuthenticateTenant::SESSION => ['via' => 'sms', 'to' => '256772123456', 'tenant_ids' => [$tenantId], 'tenant_id' => $tenantId]])
        ->get(route('tenant.issues.show', $s['issue']->id))->assertOk()->assertSee('Repair booked for 3 Nov 2026.');
});
