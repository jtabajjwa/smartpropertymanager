<?php

use App\Models\Expense;
use App\Models\ExpenseItems;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\CurrentLandlord;

/*
 * Mobile API, landlord phase 1e: Books & taxes (admins, on plans with Books).
 * Reports are the same objects the web renders, as data or PDF / Excel.
 */

afterEach(fn () => CurrentLandlord::clear());

/** Grace paid 500,000 rent this year; a 100,000 repair. */
function booksActivityForApi(array $l): void
{
    CurrentLandlord::runAs($l['landlord']->id, function () use ($l) {
        $payment = Payment::create(['number' => 'PAY-1', 'payable_type' => \App\Models\Tenant::class, 'payable_id' => $l['tenant']->id, 'amount' => 500000,
            'payment_date' => now()->toDateString(), 'payment_method' => 'cash', 'status' => 'completed']);
        app(\App\Services\TenantCredit::class)->apply($l['tenant']->id);
        Expense::create(['expense_item_id' => ExpenseItems::firstOrCreate(['name' => 'Repairs'])->id, 'estate_id' => $l['unit']->estate_id, 'amount' => 100000, 'date' => now()->toDateString()]);
    });
    CurrentLandlord::clear();
}

test('the books summary shows the year\'s income, expenses, taxes and the reports available', function () {
    $l = apiLandlord();
    booksActivityForApi($l);
    $token = apiToken($this, $l['admin']);

    $summary = $this->withToken($token)->getJson(route('api.v1.books.summary'))->assertOk()->json();
    expect($summary['income'])->toEqual(500000)->and($summary['expenses'])->toEqual(100000)->and($summary['net'])->toEqual(400000)
        ->and(collect($summary['reports'])->pluck('key')->all())->toContain('income-statement', 'by-property', 'taxes', 'balance-sheet');
});

test('a report comes as data with sections, totals and its check, and as PDF or Excel', function () {
    $l = apiLandlord();
    booksActivityForApi($l);
    $token = apiToken($this, $l['admin']);

    $report = $this->withToken($token)->getJson(route('api.v1.books.report', 'income-statement'))->assertOk()->json();
    expect($report['title'])->toBe('Income statement')
        ->and(collect($report['blocks'])->where('kind', 'section')->pluck('key')->all())->toContain('income', 'expense')
        ->and(collect($report['blocks'])->where('kind', 'total')->last()['values'][0])->toEqual(400000);

    $this->withToken($token)->getJson(route('api.v1.books.report', 'balance-sheet'))->assertOk()->assertJsonPath('check.ok', true);
    $this->withToken($token)->get(route('api.v1.books.report', ['income-statement', 'format' => 'pdf']))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->withToken($token)->get(route('api.v1.books.report', ['by-property', 'format' => 'xlsx']))->assertOk();
    $this->withToken($token)->get(route('api.v1.books.pack'))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->withToken($token)->getJson(route('api.v1.books.report', 'nonsense'))->assertNotFound();
});

test('books follow the plan: no Books, no access; no advanced books, no balance sheet', function () {
    $l = apiLandlord();
    $token = apiToken($this, $l['admin']);
    $basic = Plan::factory()->create(['features' => ['books']]);
    $none = Plan::factory()->create(['features' => []]);

    $l['landlord']->update(['plan_id' => $basic->id, 'subscription_status' => 'active', 'current_period_end' => now()->addMonth()]);
    $this->withToken($token)->getJson(route('api.v1.books.summary'))->assertOk()->assertJsonMissing(['key' => 'balance-sheet']);
    $this->withToken($token)->getJson(route('api.v1.books.report', 'balance-sheet'))->assertForbidden()->assertJsonPath('code', 'feature_unavailable');

    $l['landlord']->update(['plan_id' => $none->id]);
    $this->withToken($token)->getJson(route('api.v1.books.summary'))->assertForbidden()->assertJsonPath('code', 'feature_unavailable');
});
