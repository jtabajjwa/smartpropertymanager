<?php

namespace App\Http\Controllers;

use App\Exports\SecurityDepositsExport;
use App\Http\Requests\StoreSecurityDepositLedgerRequest;
use App\Http\Requests\UpdateSecurityDepositLedgerRequest;
use App\Models\Lease;
use App\Models\SecurityDepositLedger;
use App\Services\DepositException;
use App\Services\SecurityDeposits;
use App\Services\SmsNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SecurityDepositLedgerController extends Controller
{
    public function index()
    {
        $query = Lease::with(['tenant', 'unit.estate', 'securityDepositLedgers' => fn ($q) => $q->with('expense:id,number')->orderBy('date')->orderBy('id')])
            ->has('securityDepositLedgers');

        if (request('tenant_id')) {
            $query->where('tenant_id', request('tenant_id'));
        }

        if (request('estate_id')) {
            $query->whereHas('unit', fn ($q) => $q->where('estate_id', request('estate_id')));
        }

        if (request('type')) {
            $query->whereHas('securityDepositLedgers', fn ($q) => $q->where('type', request('type')));
        }

        if (request('search')) {
            $search = request('search');
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('tenant', fn ($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        $leases = $query->latest()->paginate(50);

        // Calculate per-lease breakdown
        $leaseDeposits = $leases->getCollection()->map(function (Lease $lease) {
            $entries = $lease->securityDepositLedgers;
            $deposit = $entries->where('type', 'deposit')->sum('amount');
            $deductions = $entries->where('type', 'deduction')->sum('amount');
            $refunds = $entries->where('type', 'refund')->sum('amount');
            $forfeits = $entries->where('type', 'forfeit')->sum('amount');
            $balance = bcsub(bcsub(bcsub((string) $deposit, (string) $deductions, 2), (string) $refunds, 2), (string) $forfeits, 2);

            return (object) [
                'lease' => $lease,
                'entries' => $entries,
                'deposit' => (float) $deposit,
                'deductions' => (float) $deductions,
                'refunds' => (float) $refunds,
                'forfeits' => (float) $forfeits,
                'balance' => (float) $balance,
            ];
        });

        // Calculate overall totals from the DB (not just current page)
        $totalsQuery = SecurityDepositLedger::query();
        if (request('tenant_id')) {
            $totalsQuery->whereHas('lease', fn ($q) => $q->where('tenant_id', request('tenant_id')));
        }
        if (request('estate_id')) {
            $totalsQuery->whereHas('lease.unit', fn ($q) => $q->where('estate_id', request('estate_id')));
        }
        if (request('type')) {
            $totalsQuery->where('type', request('type'));
        }
        if (request('search')) {
            $search = request('search');
            $totalsQuery->whereHas('lease', function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('tenant', fn ($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }
        $totalsRaw = $totalsQuery->selectRaw("
            COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END), 0) as deposit,
            COALESCE(SUM(CASE WHEN type = 'deduction' THEN amount ELSE 0 END), 0) as deductions,
            COALESCE(SUM(CASE WHEN type = 'refund' THEN amount ELSE 0 END), 0) as refunds,
            COALESCE(SUM(CASE WHEN type = 'forfeit' THEN amount ELSE 0 END), 0) as forfeits
        ")->first();

        $totals = (object) [
            'deposit' => (float) $totalsRaw->deposit,
            'deductions' => (float) $totalsRaw->deductions,
            'refunds' => (float) $totalsRaw->refunds,
            'forfeits' => (float) $totalsRaw->forfeits,
            'balance' => (float) bcsub(bcsub(bcsub((string) $totalsRaw->deposit, (string) $totalsRaw->deductions, 2), (string) $totalsRaw->refunds, 2), (string) $totalsRaw->forfeits, 2),
        ];

        $expenseItems = \App\Models\ExpenseItems::orderBy('name')->get(['id', 'name']);
        $repairItemId = $expenseItems->first(fn ($i) => str_contains(mb_strtolower($i->name), 'repair'))?->id ?? $expenseItems->first()?->id;

        return view('security-deposit-ledgers.index', compact('leaseDeposits', 'leases', 'totals', 'expenseItems', 'repairItemId'));
    }

    public function export(Request $request)
    {
        return (new SecurityDepositsExport($request))->download('security-deposits.csv');
    }

    public function store(StoreSecurityDepositLedgerRequest $request, SecurityDeposits $deposits)
    {
        $amount = (string) $request->amount;

        // Using the deposit to pay for something: reason, receipt, and (optionally) the expense.
        if ($request->type === 'deduction') {
            try {
                $result = $deposits->deduct(
                    Lease::with('tenant')->findOrFail($request->lease_id), $amount, $request->date, $request->category, $request->notes,
                    $request->file('attachment'), $request->boolean('record_expense') ? (int) $request->expense_item_id : null,
                );
            } catch (DepositException $e) {
                return redirect()->back()->withInput()->with('error', $e->getMessage());
            }

            app(SmsNotificationService::class)->depositDeduction($result['entry']->load('lease.tenant'), $result['remaining']);

            return redirect()->back()->with('success', 'Deduction recorded'.($result['entry']->expense_id ? ' and added to your expenses' : '').'. The tenant can see it in their portal.');
        }

        try {
            $result = DB::transaction(function () use ($request, $amount, $deposits) {
                $balance = $deposits->balance((int) $request->lease_id, true);

                if (bccomp($amount, $balance, 2) > 0) {
                    throw new \Exception('Amount (UGX '.number_format((float) $amount).') exceeds available deposit balance (UGX '.number_format((float) $balance).').');
                }

                $entry = SecurityDepositLedger::create($request->safe()->only(['date', 'lease_id', 'type', 'amount', 'notes']));

                return ['entry' => $entry, 'remaining' => (float) bcsub($balance, $amount, 2)];
            });

            if ($result['entry']->type === 'refund') {
                app(SmsNotificationService::class)->depositRefund($result['entry']->load('lease.tenant'), $result['remaining']);
            }
        } catch (\Exception $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Ledger entry created successfully.');
    }

    public function update(UpdateSecurityDepositLedgerRequest $request, SecurityDepositLedger $securityDepositLedger)
    {
        if ($securityDepositLedger->type === 'deposit') {
            return redirect()->back()->with('error', 'Deposit entries are system-managed and cannot be edited.');
        }

        $amount = (string) $request->input('amount', $securityDepositLedger->amount);

        try {
            DB::transaction(function () use ($request, $securityDepositLedger, $amount) {
                // Lock all ledger entries for this lease
                $leaseEntries = SecurityDepositLedger::where('lease_id', $securityDepositLedger->lease_id)->lockForUpdate()->get();

                $deposit = (string) $leaseEntries->where('type', 'deposit')->sum('amount');
                $deductions = (string) $leaseEntries->where('type', 'deduction')->sum('amount');
                $refunds = (string) $leaseEntries->where('type', 'refund')->sum('amount');
                $forfeits = (string) $leaseEntries->where('type', 'forfeit')->sum('amount');
                $balance = bcsub(bcsub(bcsub($deposit, $deductions, 2), $refunds, 2), $forfeits, 2);

                // Add back the current entry's amount (since we're updating it)
                $balance = bcadd($balance, (string) $securityDepositLedger->amount, 2);

                if (bccomp($amount, $balance, 2) > 0) {
                    throw new \Exception('Amount exceeds available deposit balance (UGX '.number_format((float) $balance).').');
                }

                if ($securityDepositLedger->expense_id && $request->input('type', 'deduction') !== 'deduction') {
                    throw new \Exception('This deduction paid for an expense, so it must stay a deduction. Delete it instead.');
                }

                $securityDepositLedger->update($request->validated());
                app(SecurityDeposits::class)->syncToExpense($securityDepositLedger->fresh('expense'));
            });
        } catch (\Exception $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Ledger entry updated successfully.');
    }

    public function destroy(SecurityDepositLedger $securityDepositLedger)
    {
        if ($securityDepositLedger->type === 'deposit') {
            return redirect()->back()->with('error', 'Deposit entries are system-managed and cannot be deleted.');
        }

        $hadExpense = (bool) $securityDepositLedger->expense_id;
        app(SecurityDeposits::class)->deleteTogether($securityDepositLedger, null);

        return redirect()->back()->with('success', $hadExpense ? 'Deduction and its expense deleted.' : 'Ledger entry deleted successfully.');
    }
}
