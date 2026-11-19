<?php

namespace App\Http\Controllers\Api\V1;

use App\Accounting\LandlordBooks;
use App\Accounting\LandlordLedger;
use App\Accounting\Reports\Report;
use App\Accounting\Reports\ReportPdf;
use App\Accounting\Reports\ReportSpreadsheet;
use App\Http\Controllers\Controller;
use App\Models\Landlord;
use App\Services\CurrentLandlord;
use App\Support\PlanFeatures;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Books & taxes for the app (admins, on plans with Books): the year at a
 * glance, and every report the web has, as data to show or as PDF / Excel
 * to share. Reports are the same objects the web renders, so they agree.
 */
class BooksController extends Controller
{
    /** report => [needs the advanced books feature, period kind] */
    public const REPORTS = [
        'income-statement' => ['Income statement', false, 'period'],
        'by-property' => ['By property', false, 'period'],
        'taxes' => ['Taxes', false, 'year'],
        'balance-sheet' => ['Balance sheet', true, 'as_at'],
        'trial-balance' => ['Trial balance', true, 'as_at'],
    ];

    public function summary(Request $request)
    {
        [$landlord, $books] = $this->books();
        $year = $this->year($request, $books);
        [$from, $to] = $books->fiscalYearRange($year);
        $statement = $books->incomeStatement($from, $to);
        $taxes = $books->computeTaxes($year);
        $income = $statement->sectionTotal('income', 0);
        $expenses = $statement->sectionTotal('expense', 0);
        $advanced = PlanFeatures::allows('books_advanced');

        return response()->json([
            'year' => $year,
            'year_label' => $books->fiscalYearLabel($year),
            'years' => collect(range($books->fiscalYearOf(), $books->fiscalYearOf() - 5))->map(fn ($y) => ['year' => $y, 'label' => $books->fiscalYearLabel($y)])->values(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'basis' => $books->booksBasisLabel(),
            'income' => $income,
            'expenses' => $expenses,
            'net' => round($income - $expenses, 2),
            'taxes' => [
                'gross_rental' => $taxes['gross_rental'],
                'due' => round(collect($taxes['lines'])->sum('due'), 2),
                'paid' => round(collect($taxes['lines'])->sum('paid'), 2),
                'lines' => collect($taxes['lines'])->map(fn ($l) => ['label' => $l['label'], 'authority' => $l['authority'], 'due' => $l['due'], 'paid' => $l['paid'], 'working' => $l['working']])->values(),
            ],
            'reports' => collect(self::REPORTS)->filter(fn ($r) => $advanced || !$r[1])->map(fn ($r, $key) => ['key' => $key, 'title' => $r[0], 'period' => $r[2]])->values(),
        ]);
    }

    /** One report as data (?year= or ?from=&to=, or ?as_at=), or as ?format=pdf|xlsx. */
    public function report(Request $request, string $report)
    {
        abort_unless(isset(self::REPORTS[$report]), 404);
        if (self::REPORTS[$report][1] && !PlanFeatures::allows('books_advanced')) {
            return response()->json(['message' => PlanFeatures::name('books_advanced').' is not included in your plan.', 'code' => 'feature_unavailable'], 403);
        }
        [$landlord, $books] = $this->books();

        $built = match ($report) {
            'income-statement' => $books->incomeStatement(...$this->period($request, $books)),
            'by-property' => $books->byProperty(...$this->period($request, $books)),
            'taxes' => $books->taxReport($this->year($request, $books)),
            'balance-sheet' => $this->balanceSheet($request, $landlord, $books),
            'trial-balance' => LandlordLedger::for($landlord)->trialBalance($this->asAt($request)),
        };

        return $this->respond($request, [$built]);
    }

    /** The year's filing pack: every report in one PDF or workbook. */
    public function pack(Request $request)
    {
        [$landlord, $books] = $this->books();
        $year = $this->year($request, $books);
        [$from, $to] = $books->fiscalYearRange($year);
        $end = $to->isFuture() ? CarbonImmutable::now()->endOfDay() : $to;
        $ledger = LandlordLedger::for($landlord);
        $advanced = PlanFeatures::allows('books_advanced');

        $request->merge(['format' => $request->input('format') === 'xlsx' ? 'xlsx' : 'pdf']);

        return $this->respond($request, array_values(array_filter([
            $books->incomeStatement($from, $to),
            $advanced ? $ledger->balanceSheet($end, $from->subDay()->endOfDay()) : null,
            $books->byProperty($from, $to),
            $advanced ? $ledger->trialBalance($end) : null,
            $books->taxReport($year),
        ])), 'Tax pack '.$books->fiscalYearLabel($year));
    }

    // ------------------------------------------------------------------------------

    protected function respond(Request $request, array $reports, ?string $packTitle = null)
    {
        $first = $reports[0];
        $name = Str::slug(($first->entity ?? config('app.name')).' '.($packTitle ?? $first->title.' '.$first->period));

        return match ($request->input('format')) {
            'pdf' => app(ReportPdf::class)->makeMany($reports, $packTitle)->download($name.'.pdf'),
            'xlsx' => app(ReportSpreadsheet::class)->downloadMany($reports, $name.'.xlsx'),
            default => response()->json(self::reportData($first)),
        };
    }

    /** A report as data: columns, sections of rows with subtotals, totals placed where the web shows them, notes and the check. */
    public static function reportData(Report $r): array
    {
        $round = fn (array $values) => array_map(fn ($v) => round((float) $v, 2), $values);

        return [
            'title' => $r->title,
            'period' => $r->period,
            'entity' => $r->entity,
            'columns' => $r->columns,
            'blocks' => collect($r->blocks())->map(fn (array $b) => $b['kind'] === 'section'
                ? [
                    'kind' => 'section',
                    'key' => $b['key'],
                    'title' => $b['title'],
                    'rows' => collect($b['rows'])->map(fn ($row) => ['code' => $row['code'] ?? null, 'name' => $row['name'], 'values' => $round($row['values']), 'note' => $row['note'] ?? null])->values(),
                    'total_label' => $b['total_label'],
                    'total' => $b['total_label'] ? array_map(fn ($i) => $r->sectionTotal($b['key'], $i), array_keys($r->columns)) : null,
                ]
                : [
                    'kind' => 'total',
                    'label' => $b['label'],
                    'grand' => (bool) $b['grand'],
                    'values' => array_map(fn ($i) => $r->totalValue($b, $i), array_keys($r->columns)),
                ])->values(),
            'notes' => $r->notes,
            'check' => $r->check ? ['label' => $r->check[0], 'ok' => (bool) $r->check[1]] : null,
        ];
    }

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

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} a fiscal year, or ?from=&to= */
    protected function period(Request $request, LandlordBooks $books): array
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        if ($request->filled('from') && !$request->filled('year')) {
            return [CarbonImmutable::parse($request->input('from'))->startOfDay(),
                ($request->filled('to') ? CarbonImmutable::parse($request->input('to')) : CarbonImmutable::now())->endOfDay()];
        }

        return $books->fiscalYearRange($this->year($request, $books));
    }

    protected function asAt(Request $request): CarbonImmutable
    {
        $request->validate(['as_at' => ['nullable', 'date']]);

        return ($request->filled('as_at') ? CarbonImmutable::parse($request->input('as_at')) : CarbonImmutable::now())->endOfDay();
    }

    protected function balanceSheet(Request $request, Landlord $landlord, LandlordBooks $books): Report
    {
        $asAt = $this->asAt($request);

        return LandlordLedger::for($landlord)->balanceSheet($asAt, $books->fiscalYearRange($books->fiscalYearOf($asAt))[0]->subDay()->endOfDay());
    }
}
