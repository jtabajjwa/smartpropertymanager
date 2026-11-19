<?php

use App\Models\Expense;
use App\Models\ExpenseItems;
use App\Models\Issue;
use App\Models\SecurityDepositLedger;
use App\Services\CurrentLandlord;
use App\Services\SmsService;

/*
 * Mobile API, landlord phase 1c: expenses (including paying from a tenant's
 * deposit) and answering tenants' issues, with the web's rules.
 */

beforeEach(fn () => $this->mock(SmsService::class, fn ($m) => $m->shouldReceive('send')->andReturn(true)));
afterEach(fn () => CurrentLandlord::clear());

test('expenses are recorded, listed with a total, changed and deleted', function () {
    ['admin' => $admin, 'unit' => $unit, 'landlord' => $landlord] = apiLandlord();
    $token = apiToken($this, $admin);
    $repairs = CurrentLandlord::runAs($landlord->id, fn () => ExpenseItems::firstOrCreate(['name' => 'Repairs']));
    CurrentLandlord::clear();

    $options = $this->withToken($token)->getJson(route('api.v1.expenses.options'))->assertOk()->json();
    expect(collect($options['items'])->pluck('name'))->toContain('Repairs')->and($options['estates'][0]['name'])->toBe('Acacia Court');

    $this->withToken($token)->postJson(route('api.v1.expenses.store'), ['date' => now()->addDay()->toDateString(), 'expense_item_id' => $repairs->id, 'estate_id' => $unit->estate_id, 'amount' => 5000])
        ->assertUnprocessable()->assertJsonValidationErrors('date');
    $expense = $this->withToken($token)->postJson(route('api.v1.expenses.store'), [
        'date' => today()->toDateString(), 'expense_item_id' => $repairs->id, 'estate_id' => $unit->estate_id, 'unit_id' => $unit->id, 'amount' => 80000, 'notes' => 'Fixed the tap',
    ])->assertCreated()->assertJsonPath('item', 'Repairs')->assertJsonPath('unit', 'A4')->json();

    $this->withToken($token)->getJson(route('api.v1.expenses.index'))->assertOk()->assertJsonPath('total', 80000)->assertJsonCount(1, 'data');
    $this->withToken($token)->putJson(route('api.v1.expenses.update', $expense['id']), ['amount' => 90000])->assertOk()->assertJsonPath('amount', 90000);
    $this->withToken($token)->deleteJson(route('api.v1.expenses.destroy', $expense['id']))->assertOk();
    expect(Expense::withoutGlobalScopes()->count())->toBe(0);
});

test('an expense paid from a tenant\'s deposit lowers it, and can\'t exceed it', function () {
    ['admin' => $admin, 'unit' => $unit, 'lease' => $lease, 'landlord' => $landlord] = apiLandlord();
    $token = apiToken($this, $admin);
    $repairs = CurrentLandlord::runAs($landlord->id, function () use ($lease) {
        SecurityDepositLedger::create(['lease_id' => $lease->id, 'type' => 'deposit', 'date' => '2026-10-01', 'amount' => 200000]);

        return ExpenseItems::firstOrCreate(['name' => 'Repairs']);
    });
    CurrentLandlord::clear();

    $this->withToken($token)->getJson(route('api.v1.expenses.options'))->assertOk()->assertJsonPath('deposit_leases.0.lease_id', $lease->id)->assertJsonPath('deposit_leases.0.balance', 200000);

    $base = ['date' => today()->toDateString(), 'expense_item_id' => $repairs->id, 'estate_id' => $unit->estate_id, 'unit_id' => $unit->id,
        'paid_from_deposit' => 1, 'deposit_lease_id' => $lease->id, 'deposit_category' => 'damage_repair', 'notes' => 'Broken window'];
    $this->withToken($token)->postJson(route('api.v1.expenses.store'), $base + ['amount' => 250000])->assertUnprocessable()->assertJsonValidationErrors('amount');
    expect(Expense::withoutGlobalScopes()->count())->toBe(0);

    $this->withToken($token)->postJson(route('api.v1.expenses.store'), $base + ['amount' => 60000])->assertCreated()
        ->assertJsonPath('deposit_left', 140000)->assertJsonPath('from_deposit.tenant', 'Grace Namata');
});

test('issues are listed by status, shown with their history, and answered for the tenant', function () {
    ['admin' => $admin, 'tenant' => $tenant, 'unit' => $unit, 'lease' => $lease, 'landlord' => $landlord] = apiLandlord();
    $theirs = apiLandlord();
    $token = apiToken($this, $admin);
    $issue = CurrentLandlord::runAs($landlord->id, fn () => Issue::create(['tenant_id' => $tenant->id, 'lease_id' => $lease->id, 'unit_id' => $unit->id,
        'category' => 'repair', 'title' => 'Leaking tap', 'description' => 'Kitchen tap drips', 'status' => 'open']));
    $other = CurrentLandlord::runAs($theirs['landlord']->id, fn () => Issue::create(['tenant_id' => $theirs['tenant']->id, 'category' => 'repair', 'title' => 'Not yours', 'description' => 'x', 'status' => 'open']));
    CurrentLandlord::clear();

    $this->withToken($token)->getJson(route('api.v1.issues.index'))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Leaking tap')->assertJsonPath('data.0.category_label', 'Repair / maintenance')->assertJsonPath('counts.open', 1);
    $this->withToken($token)->getJson(route('api.v1.issues.show', $other))->assertNotFound();

    $this->withToken($token)->postJson(route('api.v1.issues.respond', $issue), ['status' => 'open'])->assertUnprocessable()->assertJsonValidationErrors('note');
    $this->withToken($token)->postJson(route('api.v1.issues.respond', $issue), ['status' => 'in_progress', 'note' => 'Plumber coming Friday'])->assertOk()
        ->assertJsonPath('status', 'in_progress')
        ->assertJsonPath('updates', fn ($u) => end($u)['note'] === 'Plumber coming Friday' && end($u)['by'] === $admin->name && end($u)['status'] === 'in_progress');
    $this->withToken($token)->postJson(route('api.v1.issues.respond', $issue), ['status' => 'resolved'])->assertOk()->assertJsonPath('status', 'resolved');

    expect($issue->fresh()->resolved_at)->not->toBeNull();
    $this->withToken($token)->getJson(route('api.v1.issues.index'))->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($token)->getJson(route('api.v1.issues.index', ['status' => 'all']))->assertOk()->assertJsonCount(1, 'data');
});
