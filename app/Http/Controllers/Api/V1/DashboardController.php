<?php

namespace App\Http\Controllers\Api\V1;

use App\Accounting\LandlordBooks;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\SecurityDepositLedger;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\CurrentLandlord;
use Carbon\CarbonImmutable;

/** The app's home screen: the same figures as the web dashboard's cards. */
class DashboardController extends Controller
{
    public function __invoke()
    {
        $landlord = Landlord::findOrFail(CurrentLandlord::id());

        $units = Unit::selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as occupied, SUM(CASE WHEN status = 'vacant' THEN 1 ELSE 0 END) as vacant")->first();
        $open = fn ($q) => $q->whereIn('status', ['unpaid', 'partial'])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount - (SELECT COALESCE(SUM(amount), 0) FROM payment_allocations WHERE invoice_id = invoices.id)), 0) as total')->first();
        $pending = $open(Invoice::query());
        $overdue = $open(Invoice::where('due_date', '<', now()));

        // Income, expenses and net income from the income statement (current fiscal year), as on the web.
        $books = LandlordBooks::for($landlord);
        $year = $books->fiscalYearOf();
        [$from, $to] = $books->fiscalYearRange($year);
        $statement = $books->incomeStatement($from, $to);
        $income = $statement->sectionTotal('income', 0);
        $expenses = $statement->sectionTotal('expense', 0);

        $months = collect(range(5, 0))->map(function ($i) use ($books) {
            $start = CarbonImmutable::now()->startOfMonth()->subMonths($i);
            $s = $books->incomeStatement($start, $start->endOfMonth());

            return ['month' => $start->format('Y-m'), 'label' => $start->format('M'), 'income' => $s->sectionTotal('income', 0), 'expense' => $s->sectionTotal('expense', 0)];
        })->values();

        return response()->json([
            'tenants' => Tenant::count(),
            'active_leases' => Lease::where('status', 'active')->count(),
            'units' => ['total' => (int) $units->total, 'occupied' => (int) $units->occupied, 'vacant' => (int) $units->vacant,
                'occupancy_rate' => $units->total > 0 ? round($units->occupied / $units->total * 100, 1) : 0],
            'pending_invoices' => ['count' => (int) $pending->count, 'amount' => round((float) $pending->total, 2)],
            'overdue_invoices' => ['count' => (int) $overdue->count, 'amount' => round((float) $overdue->total, 2)],
            'deposits_held' => (float) SecurityDepositLedger::selectRaw("COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END), 0) as b")->value('b'),
            'net_income' => ['income' => $income, 'expenses' => $expenses, 'net' => round($income - $expenses, 2),
                'period' => $books->fiscalYearLabel($year), 'basis' => $books->booksBasisLabel()],
            'monthly' => $months,
            'recent_payments' => Payment::with('payable')->where('status', '!=', 'cancelled')->latest()->take(5)->get()->map(fn (Payment $p) => PaymentController::summary($p)),
            'expiring_leases' => Lease::with('tenant', 'unit.estate')->where('status', 'active')->whereNotNull('end_date')
                ->where('end_date', '<=', now()->addDays(30))->orderBy('end_date')->limit(10)->get()
                ->map(fn (Lease $l) => ['id' => $l->id, 'number' => $l->number, 'tenant' => $l->tenant?->name,
                    'unit' => trim(($l->unit?->estate?->name ? $l->unit->estate->name.' · ' : '').$l->unit?->name), 'end_date' => $l->end_date?->toDateString()]),
        ]);
    }
}
