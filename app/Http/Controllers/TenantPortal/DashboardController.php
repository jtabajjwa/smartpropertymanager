<?php

namespace App\Http\Controllers\TenantPortal;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\PaymentTransaction;
use App\Models\Tenant;
use App\Services\TenantPortal\TenantAccount;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $tenant = $request->attributes->get('tenant');
        $account = new TenantAccount($tenant);

        $open = $account->openInvoices();
        $leases = $tenant->leases()->with('unit.estate')->orderByRaw("status = 'active' desc")->latest('start_date')->get();

        return view('tenant-portal.dashboard', [
            'tenant' => $tenant,
            'landlord' => $tenant->landlord,
            'openInvoices' => $open,
            'outstanding' => $open->sum('balance'),
            'overdue' => $open->filter(fn ($i) => $i->due_date->lt(today()))->sum('balance'),
            'nextDue' => $open->first(fn ($i) => $i->due_date->gte(today())),
            'credit' => $account->credit(),
            'deposit' => $tenant->securityDepositBalance(),
            'leases' => $leases,
            'activeLease' => $leases->firstWhere('status', 'active'),
            'payments' => $account->payments()->latest('payment_date')->latest('id')->limit(5)->get(),
            'issues' => Issue::where('tenant_id', $tenant->id)->orderByRaw("status in ('open', 'in_progress') desc")->latest()->limit(5)->get(),
            'openIssues' => Issue::where('tenant_id', $tenant->id)->whereIn('status', ['open', 'in_progress'])->count(),
            'awaiting' => PaymentTransaction::where('purpose', 'rent')->where('payable_type', Tenant::class)->where('payable_id', $tenant->id)
                ->open()->where('created_at', '>', now()->subMinutes(15))->latest('id')->first(),
        ]);
    }

    /** All invoices, newest first. */
    public function invoices(Request $request)
    {
        $account = new TenantAccount($request->attributes->get('tenant'));

        return view('tenant-portal.invoices', [
            'invoices' => $account->invoices()->latest('invoice_date')->latest('id')->paginate(20),
        ]);
    }

    public function payments(Request $request)
    {
        $tenant = $request->attributes->get('tenant');
        $account = new TenantAccount($tenant);

        return view('tenant-portal.payments', [
            'payments' => $account->payments()->with('allocations.invoice')->latest('payment_date')->latest('id')->paginate(20),
            'pending' => PaymentTransaction::where('purpose', 'rent')->where('payable_type', Tenant::class)->where('payable_id', $tenant->id)
                ->where('status', '!=', PaymentTransaction::SUCCEEDED)->latest('id')->limit(10)->get(),
            'credit' => $account->credit(),
        ]);
    }
}
