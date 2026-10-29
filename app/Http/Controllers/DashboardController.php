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
        $monthsWindow = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i)->startOfMonth());

        $monthlyIncomeRaw = DB::table('payment_allocations')
            ->join('invoices', 'invoices.id', '=', 'payment_allocations.invoice_id')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('invoices.landlord_id', CurrentLandlord::id())
            ->where('invoices.type', '!=', 'security_deposit')
            ->where('payments.payment_date', '>=', $monthsWindow->first())
            ->selectRaw("DATE_FORMAT(payments.payment_date, '%Y-%m') as ym, SUM(payment_allocations.amount) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $monthlyExpenseRaw = Expense::where('date', '>=', $monthsWindow->first())
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') as ym, SUM(amount) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $monthlyRevenueExpense = $monthsWindow->map(function (Carbon $month) use ($monthlyIncomeRaw, $monthlyExpenseRaw) {
            $key = $month->format('Y-m');

            return [
                'label' => $month->format('M Y'),
                'income' => (float) ($monthlyIncomeRaw[$key] ?? 0),
                'expense' => (float) ($monthlyExpenseRaw[$key] ?? 0),
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
        $incomeAllocationsQuery = \App\Models\PaymentAllocation::whereHas('invoice', function ($query) {
            $query->where('type', '!=', 'security_deposit');
        });
        $forfeitsQuery = SecurityDepositLedger::where('type', 'forfeit');
        $rawPaymentsQuery = Payment::where('status', '!=', 'cancelled');

        $expensesQuery = Expense::query();
        $recentPaymentsQuery = Payment::with('payable')->where('status', '!=', 'cancelled');
        $recentInvoicesQuery = Invoice::with(['invoiceable', 'lease.tenant']);

        if ($dateFrom) {
            $incomeAllocationsQuery->whereHas('payment', fn ($q) => $q->whereDate('payment_date', '>=', $dateFrom));
            $forfeitsQuery->whereDate('date', '>=', $dateFrom);
            $rawPaymentsQuery->whereDate('payment_date', '>=', $dateFrom);
            $expensesQuery->whereDate('date', '>=', $dateFrom);
            $recentPaymentsQuery->whereDate('payment_date', '>=', $dateFrom);
            $recentInvoicesQuery->whereDate('invoice_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $incomeAllocationsQuery->whereHas('payment', fn ($q) => $q->whereDate('payment_date', '<=', $dateTo));
            $forfeitsQuery->whereDate('date', '<=', $dateTo);
            $rawPaymentsQuery->whereDate('payment_date', '<=', $dateTo);
            $expensesQuery->whereDate('date', '<=', $dateTo);
            $recentPaymentsQuery->whereDate('payment_date', '<=', $dateTo);
            $recentInvoicesQuery->whereDate('invoice_date', '<=', $dateTo);
        }

        $totalIncomeAllocations = (float) $incomeAllocationsQuery->sum('amount');
        $totalForfeits = (float) $forfeitsQuery->sum('amount');
        $totalPaymentsCollected = $totalIncomeAllocations + $totalForfeits;
        $totalRawPayments = (float) $rawPaymentsQuery->sum('amount');
        $totalExpenses = (float) $expensesQuery->sum('amount');
        $netIncome = $totalPaymentsCollected - $totalExpenses;

        $recentPayments = $recentPaymentsQuery->latest()->take(5)->get();
        $recentInvoices = $recentInvoicesQuery->latest()->take(5)->get();

        $gettingStarted = app(\App\Services\GettingStarted::class)->forDashboard(auth()->user());

        return view('dashboard', compact(
            'gettingStarted',
            'totalTenants', 'totalCustomers', 'activeLeases', 'vacantUnits', 'totalUnits',
            'occupancyRate', 'pendingInvoices', 'pendingInvoiceAmount',
            'overdueInvoices', 'overdueAmount', 'securityDepositsHeld',
            'totalPaymentsCollected', 'totalRawPayments', 'totalExpenses', 'netIncome',
            'recentPayments', 'recentInvoices', 'expiringLeases',
            'occupancyBreakdown', 'vacantUnitsList', 'topEstatesByRevenue', 'topUnitsByRevenue',
            'monthlyRevenueExpense'
        ));
    }
}
