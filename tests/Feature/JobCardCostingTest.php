<?php

use App\Models\Estate;
use App\Models\Expense;
use App\Models\ExpenseItems;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorJob;
use App\Services\CurrentLandlord;

afterEach(fn () => CurrentLandlord::clear());

/** A landlord user with an estate, a unit in it, a vendor and the starter expense items. */
function jobSetup(): array
{
    $user = User::factory()->create();
    CurrentLandlord::set($user->landlord_id);
    $estate = Estate::create(['name' => 'Acacia Court', 'district' => 'Kampala']);
    $unit = Unit::create(['name' => 'B2', 'estate_id' => $estate->id, 'status' => 'occupied']);
    $vendor = Vendor::create(['name' => 'Kato Plumbers', 'category' => 'plumber']);
    $repairs = ExpenseItems::where('name', 'Repairs & maintenance')->first();
    CurrentLandlord::clear();

    return compact('user', 'estate', 'unit', 'vendor', 'repairs');
}

function jobFields(array $s, array $extra = []): array
{
    return array_merge([
        'vendor_id' => $s['vendor']->id, 'unit_id' => $s['unit']->id, 'title' => 'Fix kitchen sink',
        'job_date' => '2026-10-20', 'status' => 'completed', 'cost' => 85000,
        'record' => 'expense', 'expense_item_id' => $s['repairs']->id, 'paid_on' => '2026-10-21',
    ], $extra);
}

function inScope(User $user, callable $fn): mixed
{
    return CurrentLandlord::runAs($user->landlord_id, $fn);
}

test('completing a job records its cost as a paid expense', function () {
    $s = jobSetup();

    $this->actingAs($s['user'])->post(route('vendor-jobs.store'), jobFields($s))
        ->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'Expense EXP-') && str_contains($m, 'UGX 85,000'));

    [$job, $expense] = inScope($s['user'], fn () => [VendorJob::first(), Expense::first()]);
    expect($job->expense_id)->toBe($expense->id)
        ->and($job->completed_on->toDateString())->toBe(today()->toDateString())
        ->and((float) $expense->amount)->toBe(85000.0)
        ->and($expense->date->toDateString())->toBe('2026-10-21')
        ->and($expense->expense_item_id)->toBe($s['repairs']->id)
        ->and($expense->estate_id)->toBe($s['estate']->id)   // taken from the unit
        ->and($expense->unit_id)->toBe($s['unit']->id)
        ->and($expense->notes)->toBe("{$job->number} · Kato Plumbers · Fix kitchen sink");

    $this->actingAs($s['user'])->get(route('expenses.index'))->assertSee('Job '.$job->number);
});

test('the job and its expense stay in step when either amount changes', function () {
    $s = jobSetup();
    $this->actingAs($s['user'])->post(route('vendor-jobs.store'), jobFields($s));
    $job = inScope($s['user'], fn () => VendorJob::first());

    // Changing the job's cost updates the expense (its notes are left alone).
    inScope($s['user'], fn () => $job->expense->update(['notes' => 'Receipt #771']));
    $this->actingAs($s['user'])->put(route('vendor-jobs.update', $job), jobFields($s, ['cost' => 95000]))->assertSessionHasNoErrors();
    $expense = inScope($s['user'], fn () => Expense::first());
    expect((float) $expense->amount)->toBe(95000.0)->and($expense->notes)->toBe('Receipt #771')
        ->and(inScope($s['user'], fn () => Expense::count()))->toBe(1);

    // Changing the expense on the Expenses page updates the job.
    $this->actingAs($s['user'])->put(route('expenses.update', $expense), ['amount' => 90000])->assertSessionHasNoErrors();
    expect((float) inScope($s['user'], fn () => $job->fresh()->cost))->toBe(90000.0);
});

test('completing a job needs a cost, an estate and an expense item, and saves nothing otherwise', function () {
    $s = jobSetup();
    $job = inScope($s['user'], fn () => VendorJob::create(['vendor_id' => $s['vendor']->id, 'title' => 'Paint gate', 'job_date' => '2026-10-01', 'status' => 'in_progress']));

    $this->actingAs($s['user'])->put(route('vendor-jobs.update', $job), jobFields($s, ['cost' => 0]))->assertSessionHasErrors('cost');
    $this->actingAs($s['user'])->put(route('vendor-jobs.update', $job), jobFields($s, ['unit_id' => null]))->assertSessionHasErrors('estate_id');
    $this->actingAs($s['user'])->put(route('vendor-jobs.update', $job), jobFields($s, ['expense_item_id' => null]))->assertSessionHasErrors('expense_item_id');

    expect(inScope($s['user'], fn () => $job->fresh()->status))->toBe('in_progress')
        ->and(inScope($s['user'], fn () => Expense::count()))->toBe(0);
});

test('a completed job can be free of charge or linked to an expense already recorded', function () {
    $s = jobSetup();

    $this->actingAs($s['user'])->post(route('vendor-jobs.store'), jobFields($s, ['title' => 'Warranty fix', 'record' => 'none']))->assertSessionHasNoErrors();
    $free = inScope($s['user'], fn () => VendorJob::where('title', 'Warranty fix')->first());
    expect($free->no_charge)->toBeTrue()->and($free->expense_id)->toBeNull()->and($free->needsExpense())->toBeFalse()
        ->and(inScope($s['user'], fn () => Expense::count()))->toBe(0);

    $existing = inScope($s['user'], fn () => Expense::create(['date' => '2026-10-10', 'expense_item_id' => $s['repairs']->id, 'estate_id' => $s['estate']->id, 'amount' => 40000]));
    $this->actingAs($s['user'])->post(route('vendor-jobs.store'), jobFields($s, ['title' => 'Gutters', 'record' => 'existing', 'expense_id' => $existing->id]))->assertSessionHasNoErrors();
    expect(inScope($s['user'], fn () => VendorJob::where('title', 'Gutters')->value('expense_id')))->toBe($existing->id)
        ->and(inScope($s['user'], fn () => Expense::count()))->toBe(1);

    // One job per expense.
    $this->actingAs($s['user'])->post(route('vendor-jobs.store'), jobFields($s, ['title' => 'Gutters again', 'record' => 'existing', 'expense_id' => $existing->id]))
        ->assertSessionHasErrors('expense_id');
    $this->actingAs($s['user'])->getJson(route('search.expenses', ['unlinked' => 1]))->assertOk()->assertJsonCount(0);
});

test('reopening a completed job can delete its expense', function () {
    $s = jobSetup();
    $this->actingAs($s['user'])->post(route('vendor-jobs.store'), jobFields($s));
    $job = inScope($s['user'], fn () => VendorJob::first());

    // Reopened without asking: the expense stays.
    $this->actingAs($s['user'])->put(route('vendor-jobs.update', $job), jobFields($s, ['status' => 'in_progress']))
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'still recorded'));
    $job = inScope($s['user'], fn () => $job->fresh());
    expect($job->completed_on)->toBeNull()->and($job->expense_id)->not->toBeNull();

    // Completed again (expense kept up to date), then cancelled, deleting the expense.
    $this->actingAs($s['user'])->put(route('vendor-jobs.update', $job), jobFields($s))->assertSessionHasNoErrors();
    $this->actingAs($s['user'])->put(route('vendor-jobs.update', $job), jobFields($s, ['status' => 'cancelled', 'remove_expense' => 1]))
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'was deleted'));
    expect(inScope($s['user'], fn () => Expense::count()))->toBe(0)
        ->and(inScope($s['user'], fn () => $job->fresh()->expense_id))->toBeNull();
});

test('completed jobs with no expense are flagged so their cost can be recorded', function () {
    $s = jobSetup();
    $job = inScope($s['user'], fn () => VendorJob::create(['vendor_id' => $s['vendor']->id, 'title' => 'Old roof job', 'job_date' => '2026-09-01', 'status' => 'completed', 'cost' => 300000]));

    $this->actingAs($s['user'])->get(route('vendor-jobs.index'))->assertOk()
        ->assertSee('1 completed job has no expense recorded')->assertSee('Record expense');
    $this->actingAs($s['user'])->get(route('vendor-jobs.index', ['costing' => 'unrecorded']))->assertOk()->assertSee('Old roof job');

    // Deleting a job's expense puts it back on that list.
    $this->actingAs($s['user'])->put(route('vendor-jobs.update', $job), jobFields($s, ['title' => 'Old roof job', 'cost' => 300000]))->assertSessionHasNoErrors();
    $this->actingAs($s['user'])->get(route('vendor-jobs.index'))->assertDontSee('has no expense recorded');
    $this->actingAs($s['user'])->delete(route('expenses.destroy', inScope($s['user'], fn () => $job->fresh()->expense_id)));
    expect(inScope($s['user'], fn () => $job->fresh()->needsExpense()))->toBeTrue();
});
