<?php

use App\Accounting\LandlordBooks;
use App\Models\Estate;
use App\Models\Expense;
use App\Models\ExpenseItems;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\CurrentLandlord;

/*
 * The dashboard's Net Income card is the income statement's net income (Books &
 * Taxes), for the current fiscal year by default or the dates chosen.
 */

afterEach(fn () => CurrentLandlord::clear());

test('the dashboard net income matches the income statement', function () {
    $this->travelTo('2026-11-12 10:00');
    $landlord = Landlord::factory()->create(['fiscal_year_start_month' => 7]);
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);
    CurrentLandlord::set($landlord->id);

    $unit = Unit::create(['name' => 'A1', 'estate_id' => Estate::create(['name' => 'Kisaasi', 'district' => 'Kampala'])->id, 'status' => 'occupied']);
    $tenant = Tenant::create([
        'type' => 'individual', 'name' => 'Grace', 'email' => 'g@example.test', 'primary_phone' => '+256772123456',
        'id_type' => 'national_id', 'id_number' => 'N1', 'emergency_contact_name' => 'E', 'emergency_contact_relationship' => 'family', 'emergency_contact_phone' => '+256700000000',
    ]);
    $lease = Lease::create([
        'number' => 'LSE-1', 'tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'status' => 'active', 'start_date' => '2026-08-01', 'rent_amount' => 500000,
        'billing_cycle' => 'monthly', 'security_deposit' => 0, 'required_upfront_periods' => 1, 'next_invoice_date' => '2026-12-01',
    ]);
    $invoice = Invoice::create([
        'number' => 'INV-1', 'lease_id' => $lease->id, 'invoiceable_type' => Lease::class, 'invoiceable_id' => $lease->id,
        'type' => 'rent', 'title' => 'Rent', 'invoice_date' => '2026-10-01', 'amount' => 500000, 'due_date' => '2026-10-01', 'status' => 'unpaid',
    ]);
    $pay = fn (int $amount, string $date) => Payment::create([
        'number' => 'PAY-'.$amount.$date, 'payable_type' => Tenant::class, 'payable_id' => $tenant->id, 'amount' => $amount,
        'payment_date' => $date, 'payment_method' => 'cash', 'status' => 'unallocated',
    ]);
    PaymentAllocation::create(['payment_id' => $pay(500000, '2026-10-05')->id, 'invoice_id' => $invoice->id, 'amount' => 500000]);
    $invoice->syncStatus();
    $pay(200000, '2026-10-20')->updateAllocationStatus();          // advance: income in the books, ignored by the old card
    $pay(300000, '2026-03-10');                                        // last fiscal year: not in this year's figure

    Expense::create(['expense_item_id' => ExpenseItems::firstOrCreate(['name' => 'Repairs'])->id, 'estate_id' => $unit->estate_id, 'amount' => 80000, 'date' => '2026-09-01']);
    // Paid to Primora: an expense in the books, ignored by the old card.
    PaymentTransaction::create([
        'reference' => 'SMS-1', 'purpose' => 'sms', 'landlord_id' => $landlord->id, 'gateway' => 'fake', 'amount' => 35000, 'fee_amount' => 0,
        'msisdn' => '256772123456', 'narrative' => 'x', 'status' => 'succeeded', 'completed_at' => '2026-09-15 10:00:00',
    ]);
    CurrentLandlord::clear();

    $booksNet = CurrentLandlord::runAs($landlord->id, function () use ($landlord) {
        $books = LandlordBooks::for($landlord->fresh());
        $statement = $books->incomeStatement(...$books->fiscalYearRange(2026));

        return $statement->sectionTotal('income', 0) - $statement->sectionTotal('expense', 0);
    });
    expect($booksNet)->toBe(700000.0 - 115000.0);

    $response = $this->actingAs($admin)->get(route('dashboard'))->assertOk();
    expect($response->viewData('netIncome'))->toBe(585000.0)
        ->and($response->viewData('totalPaymentsCollected'))->toBe(700000.0)
        ->and($response->viewData('totalExpenses'))->toBe(115000.0);
    $response->assertSee('FY 2026/27 · Cash basis')->assertSee(route('books.income-statement', ['year' => 2026]), false);

    // Chosen dates: the same calculation for those dates.
    $response = $this->actingAs($admin)->get(route('dashboard', ['date_from' => '2026-03-01', 'date_to' => '2026-03-31']))->assertOk();
    expect($response->viewData('netIncome'))->toBe(300000.0);
    $response->assertSee('01 Mar 2026 – 31 Mar 2026');
});
