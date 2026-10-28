<?php

use App\Accounting\LandlordBooks;
use App\Models\Estate;
use App\Models\Expense;
use App\Models\ExpenseItems;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\LandlordTax;
use App\Models\Lease;
use App\Models\Liquidation;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\SecurityDepositLedger;
use App\Models\StaffUser;
use App\Models\TaxPayment;
use App\Models\TaxTemplate;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\CurrentLandlord;
use Carbon\CarbonImmutable;

/*
 * A landlord's books (income statement, by property) and taxes for a fiscal year.
 * FY 2025/26 runs 1 Jul 2025 – 30 Jun 2026.
 */

afterEach(fn () => CurrentLandlord::clear());

/**
 * A landlord with one estate and lease, and in FY 2025/26:
 * a 1,000,000 rent invoice (1 Aug), paid with 1,200,000 (5 Aug: 200,000 unallocated),
 * a 500,000 rent invoice (1 Sep) that is never paid, a 300,000 expense, and a 50,000 Primora subscription.
 */
function booksLandlord(array $attributes = []): array
{
    $landlord = Landlord::factory()->create($attributes + ['books_basis' => 'cash', 'fiscal_year_start_month' => 7, 'taxpayer_type' => 'individual']);
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);

    $estate = CurrentLandlord::runAs($landlord->id, function () {
        $location = Location::create(['name' => 'Ntinda', 'country' => 'Uganda', 'district' => 'Kampala', 'address' => 'Plot 1']);
        $estate = Estate::create(['name' => 'Ntinda Flats', 'location_id' => $location->id]);
        $unit = Unit::create(['name' => 'A1', 'estate_id' => $estate->id, 'status' => 'occupied']);
        $tenant = Tenant::create([
            'type' => 'individual', 'name' => 'Sam Tenant', 'email' => fake()->unique()->safeEmail(), 'primary_phone' => fake()->unique()->e164PhoneNumber(),
            'id_type' => 'national_id', 'id_number' => 'NIN-'.fake()->unique()->randomNumber(8),
            'emergency_contact_name' => 'E', 'emergency_contact_relationship' => 'family', 'emergency_contact_phone' => '+256700000000',
        ]);
        $lease = Lease::create([
            'tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'status' => 'active', 'start_date' => '2025-07-01', 'rent_amount' => 1000000,
            'billing_cycle' => 'monthly', 'security_deposit' => 0, 'required_upfront_periods' => 1, 'next_invoice_date' => '2025-10-01',
            'number' => 'LSE-BK-'.fake()->unique()->randomNumber(6),
        ]);
        $invoice = Invoice::create(['lease_id' => $lease->id, 'invoiceable_type' => Lease::class, 'invoiceable_id' => $lease->id, 'type' => 'rent', 'title' => 'August rent', 'invoice_date' => '2025-08-01', 'amount' => 1000000, 'due_date' => '2025-08-05']);
        Invoice::create(['lease_id' => $lease->id, 'invoiceable_type' => Lease::class, 'invoiceable_id' => $lease->id, 'type' => 'rent', 'title' => 'September rent', 'invoice_date' => '2025-09-01', 'amount' => 500000, 'due_date' => '2025-09-05']);
        $payment = Payment::create(['payable_type' => Tenant::class, 'payable_id' => $tenant->id, 'amount' => 1200000, 'payment_date' => '2025-08-05', 'payment_method' => 'cash']);
        PaymentAllocation::create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => 1000000]);
        Expense::create(['date' => '2025-09-10', 'expense_item_id' => ExpenseItems::create(['name' => 'Repairs'])->id, 'estate_id' => $estate->id, 'amount' => 300000]);

        return $estate;
    });

    PaymentTransaction::create([
        'reference' => 'SUB-'.fake()->unique()->bothify('######'), 'purpose' => 'subscription', 'landlord_id' => $landlord->id, 'gateway' => 'fake',
        'amount' => 50000, 'fee_amount' => 0, 'msisdn' => '256772123456', 'narrative' => 'x', 'status' => 'succeeded', 'completed_at' => '2025-10-01 10:00:00',
    ]);

    return [$landlord, $admin, $estate];
}

function fy(Landlord $landlord): array
{
    return LandlordBooks::for($landlord->fresh())->fiscalYearRange(2025);
}

function switchOn(Landlord $landlord, string $code, array $settings = [], ?int $estateId = null): LandlordTax
{
    return CurrentLandlord::runAs($landlord->id, fn () => LandlordTax::create([
        'tax_template_id' => TaxTemplate::where('code', $code)->value('id'), 'estate_id' => $estateId, 'is_enabled' => true, 'settings' => $settings,
    ]));
}

test('cash basis counts money received, with unallocated money as rent in advance', function () {
    [$landlord] = booksLandlord();

    $report = CurrentLandlord::runAs($landlord->id, fn () => LandlordBooks::for($landlord)->incomeStatement(...fy($landlord)));

    expect($report->sectionTotal('income', 0))->toBe(1200000.0)
        ->and(collect($report->sections[0]['rows'])->pluck('values.0', 'name')->all())->toBe(['Rent received' => 1000000.0, 'Rent received in advance (not yet invoiced)' => 200000.0])
        ->and($report->sectionTotal('expense', 0))->toBe(350000.0) // repairs + Primora subscription
        ->and($report->totalValue($report->totals[0], 0))->toBe(850000.0)
        ->and($report->entity)->toBe($landlord->name);
});

test('accrual basis counts invoices raised, not money received', function () {
    [$landlord] = booksLandlord(['books_basis' => 'accrual']);

    $report = CurrentLandlord::runAs($landlord->id, fn () => LandlordBooks::for($landlord)->incomeStatement(...fy($landlord)));

    expect($report->sectionTotal('income', 0))->toBe(1500000.0);
});

test('income by property puts unlinked items under general', function () {
    [$landlord] = booksLandlord();

    $report = CurrentLandlord::runAs($landlord->id, fn () => LandlordBooks::for($landlord)->byProperty(...fy($landlord)));
    $rows = collect($report->sections[0]['rows'])->keyBy('name');

    expect($rows['Ntinda Flats']['values'])->toBe([1000000.0, 300000.0, 700000.0])
        ->and($rows['Not linked to a property (general)']['values'])->toBe([200000.0, 50000.0, 150000.0]);
});

test('individual rental income tax is a rate on gross rent above the threshold', function () {
    [$landlord] = booksLandlord();
    switchOn($landlord, 'rental_income_tax', ['threshold' => 200000]);

    $line = CurrentLandlord::runAs($landlord->id, fn () => LandlordBooks::for($landlord)->computeTaxes(2025))['lines'][0];

    // 12% × (1,200,000 − 200,000); the expense doesn't reduce it.
    expect($line['due'])->toBe(120000.0);
});

test('company rental income tax deducts expenses, capped at a share of rent', function () {
    [$landlord] = booksLandlord(['taxpayer_type' => 'company']);
    switchOn($landlord, 'rental_income_tax');

    $due = fn () => CurrentLandlord::runAs($landlord->id, fn () => LandlordBooks::for($landlord->fresh())->computeTaxes(2025))['lines'][0]['due'];

    expect($due())->toBe(255000.0); // 30% × (1,200,000 − 350,000)

    LandlordTax::withoutGlobalScopes()->update(['settings' => ['expense_cap_percent' => 10]]);
    expect($due())->toBe(324000.0); // expenses capped at 120,000
});

test('local service tax uses the band for average monthly income, or the amount the landlord set', function () {
    [$landlord] = booksLandlord();
    $tax = switchOn($landlord, 'local_service_tax');

    $due = fn () => CurrentLandlord::runAs($landlord->id, fn () => LandlordBooks::for($landlord)->computeTaxes(2025))['lines'][0]['due'];

    expect($due())->toBe(0.0); // 100,000 a month: lowest band

    CurrentLandlord::runAs($landlord->id, fn () => Payment::create(['amount' => 6, 'payment_date' => '2025-12-01', 'payment_method' => 'cash']));
    expect($due())->toBe(5000.0); // 100,000.50 a month: falls in the gap, takes the 100,001 band

    $tax->update(['settings' => ['amount' => 25000]]);
    expect($due())->toBe(25000.0);
});

test('property rates per estate, and paid levies count as expenses', function () {
    [$landlord, , $estate] = booksLandlord();
    $tax = switchOn($landlord, 'property_rates', ['rateable_value' => 10000000], $estate->id);

    CurrentLandlord::runAs($landlord->id, fn () => TaxPayment::create(['landlord_tax_id' => $tax->id, 'fiscal_year' => 2025, 'amount' => 400000, 'paid_on' => '2026-01-15']));

    [$line, $expenses] = CurrentLandlord::runAs($landlord->id, function () use ($landlord) {
        $books = LandlordBooks::for($landlord);

        return [$books->computeTaxes(2025)['lines'][0], $books->expenses(...fy($landlord))];
    });

    expect($line['due'])->toBe(600000.0)
        ->and($line['paid'])->toBe(400000.0)
        ->and($line['label'])->toBe('Property rates · Ntinda Flats')
        ->and($expenses['Property rates'][$estate->id])->toBe(400000.0);
});

test('switched-off taxes and hidden templates are left out', function () {
    [$landlord] = booksLandlord();
    $tax = switchOn($landlord, 'trading_licence', ['amount' => 150000]);
    $taxes = fn () => CurrentLandlord::runAs($landlord->id, fn () => LandlordBooks::for($landlord)->computeTaxes(2025))['lines'];

    expect($taxes())->toHaveCount(1)->and($taxes()[0]['due'])->toBe(150000.0);

    $tax->update(['is_enabled' => false]);
    expect($taxes())->toHaveCount(0);

    $tax->update(['is_enabled' => true]);
    TaxTemplate::where('code', 'trading_licence')->update(['is_active' => false]);
    expect($taxes())->toHaveCount(0);
});

test('a landlord admin chooses taxes, records payments and downloads the tax pack', function () {
    [$landlord, $admin, $estate] = booksLandlord();
    $rit = TaxTemplate::where('code', 'rental_income_tax')->value('id');
    $rates = TaxTemplate::where('code', 'property_rates')->value('id');

    $this->actingAs($admin)->get(route('books.index'))->assertOk()->assertSee('Set up your taxes');

    $this->actingAs($admin)->put(route('books.settings'), ['books_basis' => 'accrual', 'fiscal_year_start_month' => 7, 'taxpayer_type' => 'individual', 'tin' => '1000123456'])
        ->assertRedirect(route('books.settings'));
    expect($landlord->fresh()->only(['books_basis', 'tin']))->toBe(['books_basis' => 'accrual', 'tin' => '1000123456']);

    $this->actingAs($admin)->put(route('books.settings.taxes'), ['taxes' => [
        $rit => [0 => ['enabled' => '1', 'settings' => ['rate' => '', 'threshold' => '1,000,000']]],
        $rates => [$estate->id => ['enabled' => '1', 'settings' => ['rateable_value' => '5,000,000', 'rate' => '']]],
    ]])->assertRedirect(route('books.settings'))->assertSessionHasNoErrors();

    $mine = CurrentLandlord::runAs($landlord->id, fn () => LandlordTax::all()->keyBy('tax_template_id'));
    expect($mine)->toHaveCount(2)
        ->and($mine[$rit]->settings)->toEqual(['rate' => null, 'threshold' => 1000000.0])
        ->and($mine[$rates]->estate_id)->toBe($estate->id);

    $this->actingAs($admin)->get(route('books.settings'))->assertOk()->assertSee('Ntinda Flats');
    $this->actingAs($admin)->get(route('books.taxes', ['year' => 2025]))->assertOk()
        ->assertSee('Rental income tax')->assertSee('Property rates · Ntinda Flats')->assertSee('300,000');

    $this->actingAs($admin)->post(route('books.tax-payments.store'), ['landlord_tax_id' => $mine[$rates]->id, 'fiscal_year' => 2025, 'amount' => 300000, 'paid_on' => '2026-02-01', 'reference' => 'PRN-1'])
        ->assertRedirect(route('books.taxes', ['year' => 2025]));
    $payment = CurrentLandlord::runAs($landlord->id, fn () => TaxPayment::sole());
    expect((float) $payment->amount)->toBe(300000.0)->and($payment->recorded_by_user_id)->toBe($admin->id);

    foreach (['books.income-statement', 'books.by-property'] as $route) {
        $this->actingAs($admin)->get(route($route, ['year' => 2025]))->assertOk()->assertSee($landlord->name);
    }
    $this->actingAs($admin)->get(route('books.income-statement', ['from' => '2025-08-01', 'to' => '2025-08-31']))->assertOk()->assertSee('01 Aug 2025 to 31 Aug 2025');

    $pdf = $this->actingAs($admin)->get(route('books.pack', ['year' => 2025, 'format' => 'pdf']));
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toBe('application/pdf');

    $xlsx = $this->actingAs($admin)->get(route('books.pack', ['year' => 2025, 'format' => 'xlsx']));
    $xlsx->assertOk();
    expect($xlsx->headers->get('content-disposition'))->toContain('.xlsx');

    $this->actingAs($admin)->delete(route('books.tax-payments.destroy', $payment->id))->assertRedirect();
    expect(TaxPayment::withoutGlobalScopes()->count())->toBe(0);
});

test('switching a tax off keeps its payments', function () {
    [$landlord, $admin] = booksLandlord();
    $tax = switchOn($landlord, 'trading_licence', ['amount' => 150000]);
    CurrentLandlord::runAs($landlord->id, fn () => TaxPayment::create(['landlord_tax_id' => $tax->id, 'fiscal_year' => 2025, 'amount' => 150000, 'paid_on' => '2025-09-01']));

    $this->actingAs($admin)->put(route('books.settings.taxes'), ['taxes' => [$tax->tax_template_id => [0 => ['enabled' => '0']]]])->assertRedirect();

    expect($tax->fresh()->is_enabled)->toBeFalse()
        ->and($tax->fresh()->settings)->toBe(['amount' => 150000])
        ->and(TaxPayment::withoutGlobalScopes()->count())->toBe(1);
});

test('books are for landlord admins, and only their own', function () {
    [$landlord] = booksLandlord();
    [, $otherAdmin] = booksLandlord();
    $user = User::factory()->create(['landlord_id' => $landlord->id]);
    $tax = switchOn($landlord, 'trading_licence');
    $payment = CurrentLandlord::runAs($landlord->id, fn () => TaxPayment::create(['landlord_tax_id' => $tax->id, 'fiscal_year' => 2025, 'amount' => 1000, 'paid_on' => '2025-09-01']));

    $this->actingAs($user)->get(route('books.index'))->assertForbidden();

    // Another landlord can't see or touch this landlord's taxes.
    $this->actingAs($otherAdmin)->post(route('books.tax-payments.store'), ['landlord_tax_id' => $tax->id, 'fiscal_year' => 2025, 'amount' => 5000, 'paid_on' => '2025-09-01'])
        ->assertSessionHas('error');
    $this->actingAs($otherAdmin)->delete(route('books.tax-payments.destroy', $payment->id))->assertNotFound();
    expect(TaxPayment::withoutGlobalScopes()->count())->toBe(1);
});

test('staff with the permission set default tax rates', function () {
    $template = TaxTemplate::where('code', 'local_service_tax')->firstOrFail();
    $viewer = StaffUser::factory()->withPermissions(['books.view'])->create();
    $editor = StaffUser::factory()->withPermissions(['books.tax_rates'])->create();

    $this->actingAs($viewer, 'staff')->get(route('staff.books.tax-rates'))->assertOk()->assertSee('Local service tax')->assertDontSee('Save rates');

    $payload = ['name' => 'Local service tax (LST)', 'authority' => 'Local government', 'is_active' => '1', 'deductible' => '0', 'params' => ['bands' => [
        ['min' => '0', 'max' => '100000', 'amount' => '0'],
        ['min' => '100001', 'max' => '', 'amount' => '7500'],
    ]]];
    $this->actingAs($viewer, 'staff')->put(route('staff.books.tax-rates.update', $template), $payload)->assertForbidden();
    $this->actingAs($editor, 'staff')->put(route('staff.books.tax-rates.update', $template), $payload)->assertRedirect(route('staff.books.tax-rates'));

    expect($template->fresh()->params['bands'])->toEqual([['min' => 0.0, 'max' => 100000.0, 'amount' => 0.0], ['min' => 100001.0, 'max' => null, 'amount' => 7500.0]]);

    $payload['params']['bands'][0]['max'] = '';
    $this->actingAs($editor, 'staff')->put(route('staff.books.tax-rates.update', $template), $payload)->assertSessionHas('error');
});

// --- The derived double-entry ledger: chart of accounts, trial balance, balance sheet ---

function landlordLedger(Landlord $landlord, callable $fn)
{
    return CurrentLandlord::runAs($landlord->id, fn () => $fn(\App\Accounting\LandlordLedger::for($landlord->fresh()), LandlordBooks::for($landlord->fresh())));
}

test('cash basis: the trial balance and balance sheet balance and agree with the income statement', function () {
    [$landlord] = booksLandlord();
    $asAt = CarbonImmutable::parse('2026-06-30')->endOfDay();

    [$tb, $bs, $b, $is] = landlordLedger($landlord, fn ($ledger, $books) => [
        $ledger->trialBalance($asAt), $ledger->balanceSheet($asAt, CarbonImmutable::parse('2025-06-30')), $ledger->balancesAt($asAt), $books->incomeStatement(...fy($landlord)),
    ]);

    expect($tb->check[1])->toBeTrue()->and($bs->check[1])->toBeTrue()
        ->and($b['cash'])->toBe(850000.0)          // 1,200,000 received − 300,000 repairs − 50,000 subscription
        ->and($b['rent'])->toBe(1000000.0)
        ->and($b['advance'])->toBe(200000.0)
        ->and($b->has('receivable'))->toBeFalse()   // no receivables on cash basis
        ->and($bs->sections[2]['rows'][1]['values'][0])->toBe($is->totalValue($is->totals[0], 0));
});

test('accrual basis: what tenants owe and rent paid in advance are on the balance sheet', function () {
    [$landlord] = booksLandlord(['books_basis' => 'accrual']);
    $asAt = CarbonImmutable::parse('2026-06-30')->endOfDay();

    [$tb, $bs, $b] = landlordLedger($landlord, fn ($ledger) => [$ledger->trialBalance($asAt), $ledger->balanceSheet($asAt, CarbonImmutable::parse('2025-06-30')), $ledger->balancesAt($asAt)]);

    expect($tb->check[1])->toBeTrue()->and($bs->check[1])->toBeTrue()
        ->and($b['receivable'])->toBe(500000.0)    // September rent unpaid
        ->and($b['prepaid'])->toBe(200000.0)       // received, not yet against an invoice
        ->and($b['rent'])->toBe(1500000.0)
        ->and($bs->sectionTotal('assets', 0))->toBe(1350000.0);
});

test('rent collected by Primora, fees, payouts, deposits and taxes all post and balance', function () {
    [$landlord] = booksLandlord();
    $lease = CurrentLandlord::runAs($landlord->id, fn () => Lease::first());

    // A tenant pays 400,000 through Primora; the landlord bears a 4,000 fee.
    $payment = CurrentLandlord::runAs($landlord->id, fn () => Payment::create(['payable_type' => Tenant::class, 'payable_id' => $lease->tenant_id, 'amount' => 400000, 'payment_date' => '2025-11-02', 'payment_method' => 'mtn_mobile_money']));
    PaymentTransaction::create([
        'reference' => 'RNT-LEDGER-1', 'purpose' => 'rent', 'landlord_id' => $landlord->id, 'gateway' => 'fake', 'amount' => 400000, 'fee_amount' => 4000, 'fee_bearer' => 'landlord',
        'landlord_amount' => 396000, 'msisdn' => '256772123456', 'narrative' => 'x', 'status' => 'succeeded', 'completed_at' => '2025-11-02 09:00:00', 'context' => ['payment_id' => $payment->id],
    ]);
    // Primora pays out 300,000 (2,500 charge) and settles 50,000 by hand.
    CurrentLandlord::runAs($landlord->id, fn () => Liquidation::create(['reference' => 'LIQ-TEST-1', 'landlord_id' => $landlord->id, 'method' => 'mobile_money', 'destination' => [], 'amount' => 300000, 'fee' => 2500, 'net_amount' => 297500, 'status' => Liquidation::COMPLETED, 'completed_at' => '2025-12-01 10:00:00']));
    \App\Models\LandlordSettlement::create(['landlord_id' => $landlord->id, 'amount' => 50000, 'method' => 'bank', 'settled_on' => '2025-12-15']);
    // A deposit received by hand, then 100,000 deducted and 150,000 refunded; income tax paid.
    CurrentLandlord::runAs($landlord->id, function () use ($lease) {
        SecurityDepositLedger::create(['lease_id' => $lease->id, 'type' => 'deposit', 'amount' => 500000, 'date' => '2025-07-01']);
        SecurityDepositLedger::create(['lease_id' => $lease->id, 'type' => 'deduction', 'amount' => 100000, 'date' => '2026-03-01']);
        SecurityDepositLedger::create(['lease_id' => $lease->id, 'type' => 'refund', 'amount' => 150000, 'date' => '2026-03-02']);
    });
    $rit = switchOn($landlord, 'rental_income_tax');
    CurrentLandlord::runAs($landlord->id, fn () => TaxPayment::create(['landlord_tax_id' => $rit->id, 'fiscal_year' => 2025, 'amount' => 60000, 'paid_on' => '2026-04-01']));

    $asAt = CarbonImmutable::parse('2026-06-30')->endOfDay();
    [$tb, $bs, $b, $is] = landlordLedger($landlord, fn ($ledger, $books) => [
        $ledger->trialBalance($asAt), $ledger->balanceSheet($asAt, CarbonImmutable::parse('2025-06-30')), $ledger->balancesAt($asAt), $books->incomeStatement(...fy($landlord)),
    ]);

    expect($tb->check[1])->toBeTrue()->and($bs->check[1])->toBeTrue()
        ->and($b['primora'])->toBe(46000.0)          // 400,000 − 4,000 fee − 300,000 − 50,000
        ->and($b['collection_fees'])->toBe(4000.0)
        ->and($b['payout_charges'])->toBe(2500.0)
        ->and($b['deposits'])->toBe(250000.0)        // 500,000 − 100,000 − 150,000
        ->and($b['deposit_income'])->toBe(100000.0)
        ->and($b['income_taxes'])->toBe(60000.0)
        // 850,000 + 297,500 + 50,000 + 500,000 − 150,000 − 60,000
        ->and($b['cash'])->toBe(1487500.0)
        // The income statement now shows tax below the line, and agrees with the balance sheet.
        ->and(last($is->totals)['label'])->toBe('Net income after tax')
        ->and($bs->sections[2]['rows'][1]['values'][0])->toBe($is->totalValue(last($is->totals), 0));
});

test('earlier fiscal years close to retained earnings', function () {
    [$landlord] = booksLandlord();
    CurrentLandlord::runAs($landlord->id, fn () => Payment::create(['amount' => 70000, 'payment_date' => '2025-03-01', 'payment_method' => 'cash']));

    [$tb, $b] = landlordLedger($landlord, fn ($ledger) => [$ledger->trialBalance(CarbonImmutable::parse('2026-06-30')), $ledger->balancesAt(CarbonImmutable::parse('2026-06-30'))]);

    expect($b['retained'])->toBe(70000.0)            // FY 2024/25's income
        ->and($b['advance'])->toBe(200000.0)          // this year's only
        ->and($tb->check[1])->toBeTrue();
});

test('the account ledger shows the opening balance, entries and running balance', function () {
    [$landlord] = booksLandlord();

    $l = landlordLedger($landlord, fn ($ledger) => $ledger->accountLedger('cash', CarbonImmutable::parse('2025-09-01'), CarbonImmutable::parse('2025-12-31')));

    expect($l['opening'])->toBe(1200000.0)
        ->and($l['lines']->pluck('memo')->all())->toBe(['Repairs', 'Primora subscription'])
        ->and($l['closing'])->toBe(850000.0);
});

test('landlord admins see the chart of accounts, trial balance and balance sheet, and export them', function () {
    [$landlord, $admin] = booksLandlord();
    $item = CurrentLandlord::runAs($landlord->id, fn () => ExpenseItems::where('name', 'Repairs')->first());

    $this->actingAs($admin)->get(route('books.accounts', ['as_at' => '2026-06-30']))->assertOk()
        ->assertSee('Cash, bank &amp; mobile money', false)->assertSee('Repairs')->assertSee('850,000');
    $this->actingAs($admin)->get(route('books.account', ['item:'.$item->id, 'year' => 2025]))->assertOk()->assertSee('Opening balance')->assertSee('300,000');
    $this->actingAs($admin)->get('/books/accounts/nope')->assertNotFound();

    $this->actingAs($admin)->get(route('books.trial-balance', ['as_at' => '2026-06-30']))->assertOk()->assertSee('Debits equal credits');
    $this->actingAs($admin)->get(route('books.balance-sheet', ['as_at' => '2026-06-30']))->assertOk()->assertSee('Assets equal liabilities plus equity');

    foreach (['books.trial-balance', 'books.balance-sheet'] as $route) {
        expect($this->actingAs($admin)->get(route($route, ['format' => 'pdf']))->assertOk()->headers->get('content-type'))->toBe('application/pdf');
        expect($this->actingAs($admin)->get(route($route, ['format' => 'xlsx']))->assertOk()->headers->get('content-disposition'))->toContain('.xlsx');
    }
});
