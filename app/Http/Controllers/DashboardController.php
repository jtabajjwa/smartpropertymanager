<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\SecurityDepositLedger;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\CurrentLandlord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $dateFrom = request('date_from');
        $dateTo = request('date_to');

        // --- Static cards (NOT filtered by date range) ---
        $totalTenants = Tenant::count();
        $totalCustomers = \App\Models\Customer::count();
        $activeLeases = Lease::where('status', 'active')->count();

        $unitStats = Unit::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as occupied,
            SUM(CASE WHEN status = 'vacant' THEN 1 ELSE 0 END) as vacant,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance
        ")->first();

        $totalUnits = $unitStats->total;
        $vacantUnits = $unitStats->vacant;
        $occupancyRate = $totalUnits > 0 ? round($unitStats->occupied / $totalUnits * 100, 1) : 0;

        $occupancyBreakdown = [
            'occupied' => (int) $unitStats->occupied,
            'vacant' => (int) $unitStats->vacant,
            'maintenance' => (int) $unitStats->maintenance,
        ];

        // --- Vacant units, longest-empty first ---
        $vacantUnitsList = Unit::with('estate')
            ->where('status', 'vacant')
            ->get()
            ->map(function (Unit $unit) {
                $lastLease = Lease::where('unit_id', $unit->id)
                    ->whereIn('status', ['terminated', 'evicted'])
                    ->orderByDesc('updated_at')
                    ->first();

                $vacantSince = $lastLease?->updated_at ?? $unit->created_at;

                return (object) [
                    'unit' => $unit,
                    'vacant_since' => $vacantSince,
                    'days_vacant' => (int) $vacantSince->diffInDays(now()),
                ];
            })
            ->sortByDesc('days_vacant')
            ->values();

        // --- Revenue by estate / unit (all-time, lease-based rent+utility income only) ---
        $revenueBase = fn () => DB::table('payment_allocations')
            ->join('invoices', 'invoices.id', '=', 'payment_allocations.invoice_id')
            ->join('leases', 'leases.id', '=', 'invoices.lease_id')
            ->join('units', 'units.id', '=', 'leases.unit_id')
            ->join('estates', 'estates.id', '=', 'units.estate_id')
            ->where('estates.landlord_id', CurrentLandlord::id())
            ->where('invoices.type', '!=', 'security_deposit');

        $topEstatesByRevenue = $revenueBase()
            ->select('estates.id', 'estates.name', DB::raw('SUM(payment_allocations.amount) as revenue'))
            ->groupBy('estates.id', 'estates.name')
            ->orderByDesc('revenue')
            ->limit(6)
            ->get();

        $topUnitsByRevenue = $revenueBase()
            ->select('units.id', 'units.name as unit_name', 'estates.name as estate_name', DB::raw('SUM(payment_allocations.amount) as revenue'))
            ->groupBy('units.id', 'units.name', 'estates.name')
            ->orderByDesc('revenue')
            ->limit(6)
            ->get();

        // --- Revenue vs expenses, last 6 months ---
        $monthsWindow = collect(range(5, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i)); // start of month first: on the 31st, subMonths() overflows into the wrong month

        // Each month's income and expenses from the income statement, like the Net Income card.
        $monthlyBooks = \App\Accounting\LandlordBooks::for(\App\Models\Landlord::findOrFail(CurrentLandlord::id()));
        $monthlyRevenueExpense = $monthsWindow->map(function (Carbon $month) use ($monthlyBooks) {
            $from = \Carbon\CarbonImmutable::parse($month)->startOfMonth();
            $statement = $monthlyBooks->incomeStatement($from, $from->endOfMonth());

            return [
                'label' => $month->format('M Y'),
                'income' => $statement->sectionTotal('income', 0),
                'expense' => $statement->sectionTotal('expense', 0),
            ];
        })->values();

        // Pending & overdue — NOT filtered by date range
        $pendingStats = Invoice::whereIn('status', ['unpaid', 'partial'])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount - (SELECT COALESCE(SUM(amount), 0) FROM payment_allocations WHERE invoice_id = invoices.id)), 0) as total')
            ->first();
        $pendingInvoices = $pendingStats->count;
        $pendingInvoiceAmount = $pendingStats->total;

        $overdueStats = Invoice::whereIn('status', ['unpaid', 'partial'])
            ->where('due_date', '<', now())
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount - (SELECT COALESCE(SUM(amount), 0) FROM payment_allocations WHERE invoice_id = invoices.id)), 0) as total')
            ->first();
        $overdueInvoices = $overdueStats->count;
        $overdueAmount = $overdueStats->total;

        // Security deposits held — NOT filtered (current balance)
        $securityDepositsHeld = (float) SecurityDepositLedger::selectRaw(
            "COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END), 0) as balance"
        )->value('balance');

        // Expiring leases — NOT filtered (capped at 20)
        $expiringLeases = Lease::where('status', 'active')
            ->whereNotNull('end_date')
            ->where('end_date', '<=', now()->addDays(30))
            ->with('tenant', 'unit')
            ->orderBy('end_date')
            ->limit(20)
            ->get();

        // --- Date-filtered totals ---
        $rawPaymentsQuery = Payment::where('status', '!=', 'cancelled');

        $recentPaymentsQuery = Payment::with('payable')->where('status', '!=', 'cancelled');
        $recentInvoicesQuery = Invoice::with(['invoiceable', 'lease.tenant']);

        if ($dateFrom) {
            $rawPaymentsQuery->whereDate('payment_date', '>=', $dateFrom);
            $recentPaymentsQuery->whereDate('payment_date', '>=', $dateFrom);
            $recentInvoicesQuery->whereDate('invoice_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $rawPaymentsQuery->whereDate('payment_date', '<=', $dateTo);
            $recentPaymentsQuery->whereDate('payment_date', '<=', $dateTo);
            $recentInvoicesQuery->whereDate('invoice_date', '<=', $dateTo);
        }

        $totalRawPayments = (float) $rawPaymentsQuery->sum('amount');

        // Income, expenses and net income come from the income statement (Books & Taxes),
        // so the dashboard and the books always agree. No dates chosen: the current fiscal
        // year, as Books shows by default.
        $books = \App\Accounting\LandlordBooks::for(\App\Models\Landlord::findOrFail(CurrentLandlord::id()));
        if ($dateFrom || $dateTo) {
            $periodFrom = \Carbon\CarbonImmutable::parse($dateFrom ?: '2000-01-01')->startOfDay();
            $periodTo = ($dateTo ? \Carbon\CarbonImmutable::parse($dateTo) : \Carbon\CarbonImmutable::now())->endOfDay();
            $periodLabel = ($dateFrom ? $periodFrom->format('d M Y') : 'All time').' – '.$periodTo->format('d M Y');
        } else {
            $fiscalYear = $books->fiscalYearOf();
            [$periodFrom, $periodTo] = $books->fiscalYearRange($fiscalYear);
            $periodLabel = $books->fiscalYearLabel($fiscalYear);
        }
        $statement = $books->incomeStatement($periodFrom, $periodTo);
        $totalPaymentsCollected = $statement->sectionTotal('income', 0);
        $totalExpenses = $statement->sectionTotal('expense', 0);
        $netIncome = round($totalPaymentsCollected - $totalExpenses, 2);
        $netIncomeLabel = $periodLabel.' · '.$books->booksBasisLabel();
        $netIncomeUrl = route('books.income-statement', ($dateFrom || $dateTo)
            ? ['from' => $periodFrom->toDateString(), 'to' => $periodTo->toDateString()]
            : ['year' => $fiscalYear]);

        $recentPayments = $recentPaymentsQuery->latest()->take(5)->get();
        $recentInvoices = $recentInvoicesQuery->latest()->take(5)->get();

        $gettingStarted = app(\App\Services\GettingStarted::class)->forDashboard(auth()->user());

        return view('dashboard', compact(
            'gettingStarted',
            'totalTenants', 'totalCustomers', 'activeLeases', 'vacantUnits', 'totalUnits',
            'occupancyRate', 'pendingInvoices', 'pendingInvoiceAmount',
            'overdueInvoices', 'overdueAmount', 'securityDepositsHeld',
            'totalPaymentsCollected', 'totalRawPayments', 'totalExpenses', 'netIncome', 'netIncomeLabel', 'netIncomeUrl',
            'recentPayments', 'recentInvoices', 'expiringLeases',
            'occupancyBreakdown', 'vacantUnitsList', 'topEstatesByRevenue', 'topUnitsByRevenue',
            'monthlyRevenueExpense'
        ));
    }
}
