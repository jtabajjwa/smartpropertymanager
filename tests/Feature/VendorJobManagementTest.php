<?php

use App\Models\Expense;
use App\Models\Landlord;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorJob;
use App\Services\CurrentLandlord;

// --- Access Control ---

test('user can access job cards page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('vendor-jobs.index'))
        ->assertOk();
});

test('guest cannot access job cards page', function () {
    $this->get(route('vendor-jobs.index'))
        ->assertRedirect(route('login'));
});

// --- Create Job Card ---

test('user can create a job card for a vendor', function () {
    $user = User::factory()->create();
    $vendor = Vendor::factory()->create(['landlord_id' => $user->landlord_id]);

    $this->actingAs($user)
        ->post(route('vendor-jobs.store'), [
            'vendor_id' => $vendor->id,
            'title' => 'Compound landscaping',
            'description' => 'Trimmed hedges, mowed lawn, cleared debris.',
            'job_date' => '2026-09-01',
            'status' => 'completed',
            'cost' => 150000,
            'record' => 'none',
        ])->assertRedirect()->assertSessionHas('success');

    $this->assertDatabaseHas('vendor_jobs', [
        'landlord_id' => $user->landlord_id,
        'vendor_id' => $vendor->id,
        'title' => 'Compound landscaping',
        'status' => 'completed',
    ]);

    $job = VendorJob::where('title', 'Compound landscaping')->first();
    expect($job->number)->toStartWith('JC-');
});

test('job card requires a vendor belonging to the same landlord', function () {
    $user = User::factory()->create();
    $otherLandlord = Landlord::factory()->create();
    $otherVendor = Vendor::factory()->create(['landlord_id' => $otherLandlord->id]);

    $this->actingAs($user)
        ->post(route('vendor-jobs.store'), [
            'vendor_id' => $otherVendor->id,
            'title' => 'Cross-tenant attempt',
            'job_date' => '2026-09-01',
            'status' => 'scheduled',
        ])->assertRedirect()->assertSessionHasErrors('vendor_id');
});

test('job card title and job date are required', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('vendor-jobs.store'), [])
        ->assertRedirect()
        ->assertSessionHasErrors(['vendor_id', 'title', 'job_date', 'status']);
});

// --- Link to Expense ---

function createExpenseForLandlord(int $landlordId): Expense
{
    CurrentLandlord::set($landlordId);


    $estate = \App\Models\Estate::create([
        'name' => 'Test Estate',
        'district' => 'Kampala',
    ]);

    $expenseItem = \App\Models\ExpenseItems::create(['name' => 'Test Item']);

    $expense = Expense::create([
        'date' => '2026-08-31',
        'expense_item_id' => $expenseItem->id,
        'estate_id' => $estate->id,
        'amount' => 50000,
    ]);

    CurrentLandlord::clear();

    return $expense;
}

test('user can link a job card to an expense belonging to the same landlord', function () {
    $user = User::factory()->create();
    $vendor = Vendor::factory()->create(['landlord_id' => $user->landlord_id]);

    $expense = createExpenseForLandlord($user->landlord_id);

    $this->actingAs($user)
        ->post(route('vendor-jobs.store'), [
            'vendor_id' => $vendor->id,
            'expense_id' => $expense->id,
            'title' => 'Garbage collection - August',
            'job_date' => '2026-08-31',
            'status' => 'completed',
            'cost' => 50000,
        ])->assertRedirect()->assertSessionHas('success');

    $job = VendorJob::where('title', 'Garbage collection - August')->first();
    expect($job->expense_id)->toBe($expense->id);
});

test('job card cannot link to an expense belonging to another landlord', function () {
    $user = User::factory()->create();
    $vendor = Vendor::factory()->create(['landlord_id' => $user->landlord_id]);

    $otherLandlord = Landlord::factory()->create();
    $otherExpense = createExpenseForLandlord($otherLandlord->id);

    $this->actingAs($user)
        ->post(route('vendor-jobs.store'), [
            'vendor_id' => $vendor->id,
            'expense_id' => $otherExpense->id,
            'title' => 'Cross-tenant expense link',
            'job_date' => '2026-09-01',
            'status' => 'scheduled',
        ])->assertRedirect()->assertSessionHasErrors('expense_id');
});

// --- Update Job Card ---

test('user can update a job card', function () {
    $user = User::factory()->create();
    $vendor = Vendor::factory()->create(['landlord_id' => $user->landlord_id]);
    $job = VendorJob::factory()->create(['landlord_id' => $user->landlord_id, 'vendor_id' => $vendor->id]);

    $this->actingAs($user)
        ->put(route('vendor-jobs.update', $job), [
            'vendor_id' => $vendor->id,
            'title' => 'Updated title',
            'job_date' => '2026-09-05',
            'status' => 'in_progress',
        ])->assertRedirect()->assertSessionHas('success');

    expect($job->fresh()->title)->toBe('Updated title');
    expect($job->fresh()->status)->toBe('in_progress');
});

// --- Delete Job Card ---

test('user can delete a job card', function () {
    $user = User::factory()->create();
    $vendor = Vendor::factory()->create(['landlord_id' => $user->landlord_id]);
    $job = VendorJob::factory()->create(['landlord_id' => $user->landlord_id, 'vendor_id' => $vendor->id]);

    $this->actingAs($user)
        ->delete(route('vendor-jobs.destroy', $job))
        ->assertRedirect()->assertSessionHas('success');

    $this->assertDatabaseMissing('vendor_jobs', ['id' => $job->id]);
});

// --- Vendor deletion guard ---

test('vendor with job cards cannot be deleted', function () {
    $user = User::factory()->create();
    $vendor = Vendor::factory()->create(['landlord_id' => $user->landlord_id]);
    VendorJob::factory()->create(['landlord_id' => $user->landlord_id, 'vendor_id' => $vendor->id]);

    $this->actingAs($user)
        ->delete(route('vendors.destroy', $vendor))
        ->assertRedirect()->assertSessionHas('error');

    $this->assertDatabaseHas('vendors', ['id' => $vendor->id]);
});

test('vendor with no job cards can be deleted', function () {
    $user = User::factory()->create();
    $vendor = Vendor::factory()->create(['landlord_id' => $user->landlord_id]);

    $this->actingAs($user)
        ->delete(route('vendors.destroy', $vendor))
        ->assertRedirect()->assertSessionHas('success');

    $this->assertDatabaseMissing('vendors', ['id' => $vendor->id]);
});

// --- Landlord Isolation ---

test('job cards index only shows the current landlord\'s job cards', function () {
    $landlordA = Landlord::factory()->create();
    $landlordB = Landlord::factory()->create();

    CurrentLandlord::set($landlordA->id);
    $vendorA = Vendor::create(['name' => 'Vendor A', 'category' => 'plumber']);
    VendorJob::create(['vendor_id' => $vendorA->id, 'title' => 'Job Card A', 'job_date' => '2026-09-01', 'status' => 'scheduled']);
    CurrentLandlord::clear();

    CurrentLandlord::set($landlordB->id);
    $vendorB = Vendor::create(['name' => 'Vendor B', 'category' => 'electrician']);
    VendorJob::create(['vendor_id' => $vendorB->id, 'title' => 'Job Card B', 'job_date' => '2026-09-01', 'status' => 'scheduled']);
    CurrentLandlord::clear();

    $userA = User::factory()->create(['landlord_id' => $landlordA->id]);

    $response = $this->actingAs($userA)->get(route('vendor-jobs.index'));

    $response->assertOk();
    $response->assertSee('Job Card A');
    $response->assertDontSee('Job Card B');
});
