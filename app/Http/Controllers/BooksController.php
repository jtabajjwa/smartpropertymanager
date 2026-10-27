<?php

namespace App\Http\Controllers;

use App\Accounting\LandlordBooks;
use App\Accounting\LandlordLedger;
use App\Accounting\Reports\Report;
use App\Accounting\Reports\ReportPdf;
use App\Accounting\Reports\ReportSpreadsheet;
use App\Models\Estate;
use App\Models\Landlord;
use App\Models\LandlordTax;
use App\Models\TaxPayment;
use App\Models\TaxTemplate;
use App\Models\User;
use App\Services\CurrentLandlord;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A landlord's books: income statement, results by property, and the taxes
 * and levies they owe for a fiscal year, all drawn from what they already record.
 */
class BooksController extends Controller
{
    /** Overview of a fiscal year. */
    public function index(Request $request)
    {
        [$landlord, $books] = $this->books();
        $year = $this->year($request, $books);
        [$from, $to] = $books->fiscalYearRange($year);

        $statement = $books->incomeStatement($from, $to);
        $taxes = $books->computeTaxes($year);

        return view('books.index', [
            'landlord' => $landlord,
            'books' => $books,
            'year' => $year,
            'years' => $this->years($books),
            'from' => $from,
            'to' => $to,
            'income' => $statement->sectionTotal('income', 0),
            'expenses' => $statement->sectionTotal('expense', 0),
            'taxes' => $taxes,
            'byProperty' => $books->byProperty($from, $to),
            'configured' => LandlordTax::where('is_enabled', true)->exists(),
        ]);
    }

    public function incomeStatement(Request $request)
    {
        [, $books] = $this->books();
        [$year, $from, $to] = $this->period($request, $books);

        return $this->respond($request, $books->incomeStatement($from, $to), compact('books', 'year', 'from', 'to'));
    }

    public function byProperty(Request $request)
    {
        [, $books] = $this->books();
        [$year, $from, $to] = $this->period($request, $books);

        return $this->respond($request, $books->byProperty($from, $to), compact('books', 'year', 'from', 'to'));
    }

    public function trialBalance(Request $request)
    {
        [$landlord, $books] = $this->books();
        $asAt = $this->asAt($request);

        return $this->respondAsAt($request, LandlordLedger::for($landlord)->trialBalance($asAt), compact('books', 'asAt'));
    }

    public function balanceSheet(Request $request)
    {
        [$landlord, $books] = $this->books();
        $asAt = $this->asAt($request);
        // Compare with the end of the previous fiscal year by default.
        $compareAt = $request->filled('compare') ? CarbonImmutable::parse($request->input('compare'))->endOfDay()
            : $books->fiscalYearRange($books->fiscalYearOf($asAt))[0]->subDay()->endOfDay();

        return $this->respondAsAt($request, LandlordLedger::for($landlord)->balanceSheet($asAt, $compareAt), compact('books', 'asAt', 'compareAt'));
    }

    /** The chart of accounts with balances as at a date. */
    public function accounts(Request $request)
    {
        [$landlord, $books] = $this->books();
        $asAt = $this->asAt($request);
        $ledger = LandlordLedger::for($landlord);

        return view('books.accounts', [
            'books' => $books,
            'ledger' => $ledger,
            'asAt' => $asAt,
            'accounts' => $ledger->accounts(),
            'balances' => $ledger->balancesAt($asAt),
        ]);
    }

    /** One account's entries over a period. */
    public function account(Request $request, string $account)
    {
        [$landlord, $books] = $this->books();
        $ledger = LandlordLedger::for($landlord);
        abort_unless($ledger->account($account), 404);
        [$year, $from, $to] = $this->period($request, $books);

        return view('books.account', ['books' => $books, 'year' => $year, 'from' => $from, 'to' => $to, 'years' => $this->years($books)]
            + $ledger->accountLedger($account, $from, $to));
    }

    public function taxes(Request $request)
    {
        [$landlord, $books] = $this->books();
        $year = $this->year($request, $books);

        if (in_array($request->input('format'), ['pdf', 'xlsx'], true)) {
            return $this->export($request, [$books->taxReport($year)]);
        }

        return view('books.taxes', [
            'landlord' => $landlord,
            'books' => $books,
            'year' => $year,
            'years' => $this->years($books),
            'result' => $books->computeTaxes($year),
            'payments' => TaxPayment::with('landlordTax.template', 'landlordTax.estate')->where('fiscal_year', $year)->orderByDesc('paid_on')->orderByDesc('id')->get(),
            'payable' => LandlordTax::with('template', 'estate')->get()->sortBy(fn ($t) => [$t->template->sort_order, $t->estate?->name])->values(),
        ]);
    }

    /** Everything for filing a year, in one PDF or workbook. */
    public function pack(Request $request)
    {
        [$landlord, $books] = $this->books();
        $year = $this->year($request, $books);
        [$from, $to] = $books->fiscalYearRange($year);

        $ledger = LandlordLedger::for($landlord);
        $end = $to->isFuture() ? CarbonImmutable::now()->endOfDay() : $to;

        // The balance sheet and trial balance only on plans with them.
        $advanced = $landlord->hasFeature('books_advanced');

        return $this->export($request, array_values(array_filter([
            $books->incomeStatement($from, $to),
            $advanced ? $ledger->balanceSheet($end, $from->subDay()->endOfDay()) : null,
            $books->byProperty($from, $to),
            $advanced ? $ledger->trialBalance($end) : null,
            $books->taxReport($year),
        ])), 'Tax pack '.$books->fiscalYearLabel($year));
    }

    // --- Tax payments ------------------------------------------------------------

    public function storeTaxPayment(Request $request)
    {
        $data = $request->validate([
            'landlord_tax_id' => ['required', 'integer'],
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $tax = LandlordTax::with('template')->find($data['landlord_tax_id']);
        if (!$tax) {
            return back()->withInput()->with('error', 'Choose one of your taxes.');
        }

        TaxPayment::create($data + ['recorded_by_user_id' => $request->user() instanceof User ? $request->user()->id : null]);

        return redirect()->route('books.taxes', ['year' => $data['fiscal_year']])->with('success', 'Payment of UGX '.number_format((float) $data['amount']).' recorded against '.$tax->label().'.');
    }

    public function destroyTaxPayment(int $taxPayment)
    {
        $payment = TaxPayment::findOrFail($taxPayment);
        $year = $payment->fiscal_year;
        $payment->delete();

        return redirect()->route('books.taxes', ['year' => $year])->with('success', 'Tax payment removed.');
    }

    // --- Settings -----------------------------------------------------------------

    public function settings()
    {
        [$landlord] = $this->books();

        return view('books.settings', [
            'landlord' => $landlord,
            'templates' => TaxTemplate::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'estates' => Estate::orderBy('name')->get(['id', 'name']),
            'mine' => LandlordTax::all()->keyBy(fn ($t) => $t->tax_template_id.':'.($t->estate_id ?? 0)),
        ]);
    }

    public function updateSettings(Request $request)
    {
        [$landlord] = $this->books();

        $data = $request->validate([
            'books_basis' => ['required', Rule::in(['cash', 'accrual'])],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            'taxpayer_type' => ['required', Rule::in(['individual', 'company'])],
            'tin' => ['nullable', 'string', 'max:30', 'regex:/^[0-9A-Za-z\-]+$/'],
        ], ['tin.regex' => 'A TIN has only letters, numbers and dashes.']);

        if ($data['books_basis'] === 'accrual' && !$landlord->hasFeature('accrual_basis')) {
            return back()->withInput()->withErrors(['books_basis' => 'Accrual accounting is not included in your plan.']);
        }

        $landlord->update($data);

        return redirect()->route('books.settings')->with('success', 'Books settings saved.');
    }

    /** Switch taxes on or off (per estate where they apply per property) and save the landlord's figures. */
    public function updateTaxes(Request $request)
    {
        // Allow "2,820,000".
        $taxes = $request->input('taxes', []);
        if (is_array($taxes)) {
            array_walk_recursive($taxes, function (&$v, $k) {
                if (is_string($v) && $k !== 'notes') {
                    $v = str_replace([',', ' '], '', $v);
                }
            });
            $request->merge(['taxes' => $taxes]);
        }

        $request->validate([
            'taxes' => ['nullable', 'array'],
            'taxes.*.*.enabled' => ['nullable', 'boolean'],
            'taxes.*.*.notes' => ['nullable', 'string', 'max:300'],
            'taxes.*.*.settings' => ['nullable', 'array'],
            'taxes.*.*.settings.*' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
        ], ['taxes.*.*.settings.*.numeric' => 'Tax figures must be numbers.']);

        $templates = TaxTemplate::where('is_active', true)->get()->keyBy('id');
        $estates = Estate::pluck('id')->flip();
        $allowed = [
            'rental_income' => ['rate', 'threshold', 'company_rate', 'expense_cap_percent'],
            'bands' => ['amount'],
            'percent_of_value' => ['rateable_value', 'rate'],
            'fixed' => ['amount'],
        ];
        $existing = LandlordTax::all()->keyBy(fn ($t) => $t->tax_template_id.':'.($t->estate_id ?? 0));

        $on = 0;
        foreach ($templates as $template) {
            $targets = $template->perEstate() ? $estates->keys()->all() : [0];
            foreach ($targets as $estateId) {
                $input = $request->input("taxes.{$template->id}.{$estateId}", []);
                $enabled = (bool) ($input['enabled'] ?? false);
                $tax = $existing[$template->id.':'.$estateId] ?? null;

                if (!$enabled && !$tax) {
                    continue;
                }

                $settings = collect($input['settings'] ?? [])
                    ->only($allowed[$template->method] ?? [])
                    ->map(fn ($v) => ($v === null || $v === '') ? null : (float) $v)
                    ->all();

                // Keep figures not on the form (e.g. company rates while filing as an individual).
                $values = ['is_enabled' => $enabled, 'settings' => array_merge($tax?->settings ?? [], $settings), 'notes' => array_key_exists('notes', $input) ? $input['notes'] : $tax?->notes];
                if ($tax) {
                    $tax->update($values);
                } else {
                    LandlordTax::create($values + ['tax_template_id' => $template->id, 'estate_id' => $estateId ?: null]);
                }
                $on += $enabled ? 1 : 0;
            }
        }

        return redirect()->route('books.settings')->with('success', $on ? "Saved. {$on} tax".($on === 1 ? '' : 'es').' and levies switched on.' : 'Saved. No taxes are switched on.');
    }

    // ------------------------------------------------------------------------------

    /** @return array{0: Landlord, 1: LandlordBooks} */
    protected function books(): array
    {
        $landlord = Landlord::findOrFail(CurrentLandlord::id());

        return [$landlord, LandlordBooks::for($landlord)];
    }

    protected function year(Request $request, LandlordBooks $books): int
    {
        $current = $books->fiscalYearOf();
        $year = (int) $request->input('year', $current);

        return ($year >= 2000 && $year <= $current + 1) ? $year : $current;
    }

    /** Fiscal years to choose from: this one and the five before. */
    protected function years(LandlordBooks $books): array
    {
        $current = $books->fiscalYearOf();

        return collect(range($current, $current - 5))->mapWithKeys(fn ($y) => [$y => $books->fiscalYearLabel($y)])->all();
    }

    /** A fiscal year, or a custom from/to. @return array{0: ?int, 1: CarbonImmutable, 2: CarbonImmutable} */
    protected function period(Request $request, LandlordBooks $books): array
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        if ($request->filled('from') && !$request->filled('year')) {
            $from = CarbonImmutable::parse($request->input('from'))->startOfDay();
            $to = ($request->filled('to') ? CarbonImmutable::parse($request->input('to')) : CarbonImmutable::now())->endOfDay();

            return [null, $from, $to];
        }

        $year = $this->year($request, $books);

        return [$year, ...$books->fiscalYearRange($year)];
    }

    protected function asAt(Request $request): CarbonImmutable
    {
        $request->validate(['as_at' => ['nullable', 'date'], 'compare' => ['nullable', 'date']]);

        return ($request->filled('as_at') ? CarbonImmutable::parse($request->input('as_at')) : CarbonImmutable::now())->endOfDay();
    }

    protected function respondAsAt(Request $request, Report $report, array $filters)
    {
        if (in_array($request->input('format'), ['pdf', 'xlsx'], true)) {
            return $this->export($request, [$report]);
        }

        return view('books.report', ['report' => $report, 'year' => null] + $filters);
    }

    protected function respond(Request $request, Report $report, array $filters)
    {
        if (in_array($request->input('format'), ['pdf', 'xlsx'], true)) {
            return $this->export($request, [$report]);
        }

        return view('books.report', ['report' => $report, 'years' => $this->years($filters['books'])] + $filters);
    }

    protected function export(Request $request, array $reports, ?string $packTitle = null)
    {
        $first = $reports[0];
        $name = \Illuminate\Support\Str::slug(($first->entity ?? config('app.name')).' '.($packTitle ?? $first->title.' '.$first->period));

        return $request->input('format') === 'xlsx'
            ? app(ReportSpreadsheet::class)->downloadMany($reports, $name.'.xlsx')
            : app(ReportPdf::class)->makeMany($reports, $packTitle)->stream($name.'.pdf');
    }
}
