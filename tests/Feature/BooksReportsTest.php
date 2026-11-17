<?php

use App\Accounting\Ledger;
use App\Accounting\Postings;
use App\Accounting\Reports\FinancialReports;
use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Models\SmsPurchase;
use App\Models\StaffUser;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;

/*
 * Books reports: Profit & Loss, Trial Balance, Balance Sheet — on screen, as
 * PDF and as a formatted Excel workbook.
 */

/** Some activity: rent collected for a landlord, a subscription, an SMS purchase, opening capital. */
function booksActivity(): Landlord
{
    $landlord = Landlord::factory()->create(['name' => 'Kololo Homes']);
    $mk = fn ($purpose, $amount, $extra = []) => PaymentTransaction::create($extra + [
        'reference' => strtoupper($purpose).'-'.fake()->unique()->numerify('######'), 'purpose' => $purpose, 'landlord_id' => $landlord->id, 'gateway' => 'manual',
        'amount' => $amount, 'msisdn' => '256772123456', 'narrative' => 'x', 'status' => 'succeeded', 'completed_at' => now(), 'context' => ['method' => 'bank_transfer'],
    ]);
    $postings = app(Postings::class);
    $postings->paymentSucceeded($mk('rent', 500000, ['gateway' => 'yo', 'fee_amount' => 0, 'fee_bearer' => 'landlord', 'landlord_amount' => 500000]));
    $postings->paymentSucceeded($mk('subscription', 180000));
    SmsPurchase::create(['provider' => 'Marz', 'quantity' => 1000, 'total_cost' => 25000, 'paid_from' => 'bank', 'purchased_on' => today()]);
    app(Ledger::class)->post(today(), 'Opening capital', [['account' => 'bank', 'debit' => 1000000], ['account' => 'equity', 'credit' => 1000000]], 'manual');

    return $landlord;
}

test('the trial balance lists every balance and debits equal credits', function () {
    booksActivity();
    $report = app(FinancialReports::class)->trialBalance(CarbonImmutable::now()->endOfDay());

    $dr = $report->sectionTotal('accounts', 0);
    $cr = $report->sectionTotal('accounts', 1);
    expect($dr)->toBe($cr)->and($dr)->toBe(1680000.0)            // bank 1,155,000 + Yo! 500,000 + SMS 25,000
        ->and($report->check[1])->toBeTrue();

    $payable = collect($report->sections[0]['rows'])->firstWhere('code', '2000');
    expect($payable['values'])->toBe([0.0, 500000.0]);             // a credit balance
});

test('the balance sheet balances, with profit to date in equity and a comparison date', function () {
    booksActivity();
    $report = app(FinancialReports::class)->balanceSheet(CarbonImmutable::now()->endOfDay(), CarbonImmutable::now()->subMonthNoOverflow()->endOfMonth());

    expect($report->namedTotal('Total assets', 0))->toBe(1655000.0)
        ->and($report->sectionTotal('current_asset', 0))->toBe(1655000.0)
        ->and($report->namedTotal('Total liabilities', 0))->toBe(500000.0)
        ->and($report->sectionTotal('current_liability', 0))->toBe(500000.0)
        ->and($report->namedTotal('Total equity', 0))->toBe(1155000.0)   // capital 1,000,000 + profit 155,000
        ->and($report->namedTotal('Total liabilities and equity', 0))->toBe(1655000.0)
        ->and($report->check[1])->toBeTrue()
        ->and($report->namedTotal('Total assets', 1))->toBe(0.0);       // nothing at the end of last month
});

test('profit and loss shows income, expenses and net profit, with the previous period', function () {
    booksActivity();
    $report = app(FinancialReports::class)->profitAndLoss(CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now());

    expect($report->namedTotal('Total income', 0))->toBe(180000.0)
        ->and($report->namedTotal('Total expenses', 0))->toBe(25000.0)
        ->and($report->sectionTotal('cost_of_sales', 0))->toBe(25000.0)   // the SMS purchase
        ->and($report->namedTotal('Net profit', 0))->toBe(155000.0)
        ->and(end($report->totals)['label'])->toBe('Net profit');
});

test('each report renders on screen and exports to PDF and Excel', function () {
    booksActivity();
    $staff = StaffUser::factory()->withPermissions(['books.view'])->create();

    foreach (['staff.books.pnl' => 'Net profit', 'staff.books.trial-balance' => 'Debits equal credits', 'staff.books.balance-sheet' => 'Assets equal liabilities plus equity'] as $route => $see) {
        $this->actingAs($staff, 'staff')->get(route($route))->assertOk()->assertSee($see);

        $pdf = $this->actingAs($staff, 'staff')->get(route($route, ['format' => 'pdf']));
        $pdf->assertOk();
        expect($pdf->headers->get('content-type'))->toContain('application/pdf');

        $xlsx = $this->actingAs($staff, 'staff')->get(route($route, ['format' => 'xlsx']));
        $xlsx->assertOk();
        expect($xlsx->headers->get('content-type'))->toContain('spreadsheetml')
            ->and($xlsx->headers->get('content-disposition'))->toContain('.xlsx');
    }
});

test('the Excel workbook is formatted, and its formulas give the same totals', function () {
    booksActivity();
    $staff = StaffUser::factory()->withPermissions(['books.view'])->create();

    $content = $this->actingAs($staff, 'staff')->get(route('staff.books.balance-sheet', ['format' => 'xlsx']))->streamedContent();
    $path = tempnam(sys_get_temp_dir(), 'bs').'.xlsx';
    file_put_contents($path, $content);
    $sheet = IOFactory::load($path)->getActiveSheet();
    @unlink($path);

    expect($sheet->getCell('A2')->getValue())->toBe('Balance Sheet')
        ->and($sheet->getStyle('A5')->getFill()->getStartColor()->getRGB())->toBe('1C2230')   // navy header row
        ->and($sheet->getFreezePane())->toBe('A6');

    // Find the totals by label and check the formulas recalculate to the report's figures.
    $find = function (string $label) use ($sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            if ($sheet->getCell('B'.$row->getRowIndex())->getValue() === $label) {
                return $row->getRowIndex();
            }
        }

        return null;
    };
    $current = $find('Total current assets');
    $assets = $find('Total assets');
    $grand = $find('Total liabilities and equity');
    expect($sheet->getCell("C{$current}")->getValue())->toStartWith('=SUM(')
        ->and($sheet->getCell("C{$assets}")->getValue())->toBe("=C{$current}")   // categories added up, right below them
        ->and($assets)->toBeGreaterThan($current)->and($assets)->toBeLessThan($grand)
        ->and($sheet->getCell("C{$assets}")->getCalculatedValue())->toEqual(1655000)
        ->and($sheet->getCell("C{$grand}")->getCalculatedValue())->toEqual(1655000)
        ->and($sheet->getStyle("C{$assets}")->getNumberFormat()->getFormatCode())->toContain('#,##0.00');
});

test('reports need the books permission', function () {
    $staff = StaffUser::factory()->withPermissions(['landlords.view'])->create();

    foreach (['staff.books.pnl', 'staff.books.trial-balance', 'staff.books.balance-sheet'] as $route) {
        $this->actingAs($staff, 'staff')->get(route($route, ['format' => 'xlsx']))->assertForbidden();
    }
});

test('accounts have categories that group the balance sheet and profit and loss', function () {
    booksActivity();
    $staff = StaffUser::factory()->withPermissions(['books.view', 'books.post'])->create();

    // The app's own accounts start in the usual categories.
    expect(\App\Models\LedgerAccount::key('bank')->category)->toBe('current_asset')
        ->and(\App\Models\LedgerAccount::key('landlord_payable')->category)->toBe('current_liability')
        ->and(\App\Models\LedgerAccount::key('bank_charges')->category)->toBe('finance_cost');

    // A category must belong to the account's type.
    $this->actingAs($staff, 'staff')->post(route('staff.books.accounts.store'), ['code' => '1500', 'name' => 'Laptops', 'type' => 'asset', 'category' => 'current_liability'])
        ->assertSessionHasErrors('category');
    $this->actingAs($staff, 'staff')->post(route('staff.books.accounts.store'), ['code' => '1500', 'name' => 'Laptops', 'type' => 'asset', 'category' => 'non_current_asset'])->assertSessionHas('success');
    $this->actingAs($staff, 'staff')->post(route('staff.books.accounts.store'), ['code' => '2500', 'name' => 'Bank loan', 'type' => 'liability'])->assertSessionHas('success');
    $laptops = \App\Models\LedgerAccount::where('code', '1500')->sole();
    $loan = \App\Models\LedgerAccount::where('code', '2500')->sole();
    expect($laptops->category)->toBe('non_current_asset')->and($loan->category)->toBe('current_liability'); // the type's default

    $this->actingAs($staff, 'staff')->put(route('staff.books.accounts.update', $loan), ['name' => 'Bank loan', 'category' => 'non_current_liability'])->assertSessionHas('success');
    expect($loan->fresh()->category)->toBe('non_current_liability');

    app(Ledger::class)->post(today(), 'Laptops bought on a loan', [['account' => $laptops, 'debit' => 3000000], ['account' => $loan, 'credit' => 3000000]], 'manual');

    $report = app(FinancialReports::class)->balanceSheet(CarbonImmutable::now()->endOfDay(), CarbonImmutable::now()->subMonthNoOverflow()->endOfMonth());
    expect($report->sectionTotal('current_asset', 0))->toBe(1655000.0)
        ->and($report->sectionTotal('non_current_asset', 0))->toBe(3000000.0)
        ->and($report->namedTotal('Total assets', 0))->toBe(4655000.0)
        ->and($report->sectionTotal('non_current_liability', 0))->toBe(3000000.0)
        ->and($report->check[1])->toBeTrue()
        ->and(collect($report->blocks())->map(fn ($b) => $b['kind'] === 'section' ? $b['key'] : $b['label'])->all())->toBe([
            'current_asset', 'non_current_asset', 'Total assets',
            'current_liability', 'non_current_liability', 'Total liabilities',
            'capital', 'reserves', 'Total equity', 'Total liabilities and equity',
        ]);

    $this->actingAs($staff, 'staff')->get(route('staff.books.accounts'))->assertOk()->assertSeeInOrder(['Current assets', 'Non-current assets', 'Laptops', 'Current liabilities', 'Non-current liabilities', 'Bank loan']);
    $this->actingAs($staff, 'staff')->get(route('staff.books.balance-sheet'))->assertOk()->assertSeeInOrder(['Current assets', 'Total current assets', 'Non-current assets', 'Total assets', 'Current liabilities']);
    $this->actingAs($staff, 'staff')->get(route('staff.books.balance-sheet', ['format' => 'xlsx']))->assertOk();
    $this->actingAs($staff, 'staff')->get(route('staff.books.balance-sheet', ['format' => 'pdf']))->assertOk();
});
