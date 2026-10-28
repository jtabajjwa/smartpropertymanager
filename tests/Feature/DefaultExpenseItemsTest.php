<?php

use App\Accounting\LandlordLedger;
use App\Models\ExpenseItems;
use App\Models\Landlord;
use App\Services\CurrentLandlord;

afterEach(fn () => CurrentLandlord::clear());

test('a new landlord starts with the default expense items, kept to their own account', function () {
    $a = Landlord::factory()->create();
    $b = Landlord::factory()->create();

    $names = CurrentLandlord::runAs($a->id, fn () => ExpenseItems::orderBy('id')->pluck('name')->all());
    expect($names)->toBe(array_keys(ExpenseItems::DEFAULTS))
        ->and(ExpenseItems::withoutGlobalScopes()->where('landlord_id', $b->id)->count())->toBe(count(ExpenseItems::DEFAULTS));

    // Each one shows in the landlord's chart of accounts as an expense account.
    $accounts = CurrentLandlord::runAs($a->id, fn () => LandlordLedger::for($a)->accounts());
    expect($accounts->where('type', 'expense')->pluck('name')->all())->toContain('Repairs & maintenance', 'Security', 'Miscellaneous');
});

test('seeding the defaults again skips items the landlord already has', function () {
    $landlord = Landlord::factory()->create();
    CurrentLandlord::runAs($landlord->id, function () {
        ExpenseItems::where('name', 'Security')->update(['name' => 'SECURITY ']);
        ExpenseItems::where('name', 'Insurance')->delete();
    });

    ExpenseItems::seedDefaultsFor($landlord->id);

    $names = ExpenseItems::withoutGlobalScopes()->where('landlord_id', $landlord->id)->pluck('name');
    expect($names)->toHaveCount(count(ExpenseItems::DEFAULTS))
        ->and($names)->toContain('Insurance', 'SECURITY ')
        ->and($names)->not->toContain('Security');
});
