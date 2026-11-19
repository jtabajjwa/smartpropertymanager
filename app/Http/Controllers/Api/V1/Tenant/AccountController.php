<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Issue;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\SecurityDepositLedger;
use App\Models\Tenant;
use App\Services\TenantPortal\TenantAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** A signed-in tenant's account with their landlord: home, invoices, payments, deposit (as the web tenant portal). */
class AccountController extends Controller
{
    public function dashboard(Request $request)
    {
        $tenant = $request->user();
        $account = new TenantAccount($tenant);
        $open = $account->openInvoices();
        $leases = $tenant->leases()->with('unit.estate')->orderByRaw("status = 'active' desc")->latest('start_date')->get();
        $next = $open->first(fn ($i) => $i->due_date->gte(today()));

        return response()->json([
            'outstanding' => round($open->sum('balance'), 2),
            'overdue' => round($open->filter(fn ($i) => $i->due_date->lt(today()))->sum('balance'), 2),
            'next_due' => $next ? self::invoice($next) : null,
            'open_invoices' => $open->map(fn ($i) => self::invoice($i))->values(),
            'credit' => round($account->credit(), 2),
            'deposit' => round($tenant->securityDepositBalance(), 2),
            'leases' => $leases->map(fn ($l) => self::lease($l))->values(),
            'payments' => $account->payments()->with('allocations.invoice')->latest('payment_date')->latest('id')->limit(5)->get()->map(fn ($p) => self::payment($p))->values(),
            'issues' => Issue::where('tenant_id', $tenant->id)->with('unit.estate')->orderByRaw("status in ('open', 'in_progress') desc")->latest()->limit(5)->get()
                ->map(fn ($i) => IssueController::summary($i))->values(),
            'open_issue_count' => Issue::where('tenant_id', $tenant->id)->whereIn('status', ['open', 'in_progress'])->count(),
            'awaiting' => ($t = self::rentTransactions($tenant)->open()->where('created_at', '>', now()->subMinutes(15))->latest('id')->first()) ? PayController::transaction($t) : null,
        ]);
    }

    /** All invoices, newest first (?status=open for those still owing). */
    public function invoices(Request $request)
    {
        $account = new TenantAccount($request->user());

        if ($request->input('status') === 'open') {
            return response()->json(['data' => $account->openInvoices()->map(fn ($i) => self::invoice($i))->values(), 'next_page_url' => null]);
        }

        return response()->json($account->invoices()->latest('invoice_date')->latest('id')->paginate(20)->through(fn ($i) => self::invoice($i)));
    }

    public function payments(Request $request)
    {
        $tenant = $request->user();
        $account = new TenantAccount($tenant);
        $page = $account->payments()->with('allocations.invoice')->latest('payment_date')->latest('id')->paginate(20)->through(fn ($p) => self::payment($p));

        return response()->json($page->toArray() + [
            'credit' => round($account->credit(), 2),
            // Mobile money payments that haven't (yet) gone through.
            'pending' => self::rentTransactions($tenant)->where('status', '!=', PaymentTransaction::SUCCEEDED)->latest('id')->limit(10)->get()
                ->map(fn ($t) => PayController::transaction($t))->values(),
        ]);
    }

    public function deposit(Request $request)
    {
        $deposits = (new TenantAccount($request->user()))->deposits();

        return response()->json(['deposits' => $deposits->map(fn ($d) => [
            'lease' => self::lease($d->lease),
            'agreed' => $d->agreed,
            'paid' => $d->paid,
            'used' => $d->used,
            'refunded' => $d->refunded,
            'kept' => $d->kept,
            'balance' => round($d->balance, 2),
            'entries' => $d->rows->map(fn ($row) => [
                'date' => $row['entry']->date?->toDateString(),
                'type' => $row['entry']->type,
                'label' => $row['entry']->type === 'deduction' ? ($row['entry']->categoryLabel() ?? 'Used') : (SecurityDepositLedger::TYPES[$row['entry']->type][0] ?? ucfirst($row['entry']->type)),
                'notes' => $row['entry']->notes,
                'amount' => (float) $row['entry']->amount,
                'in' => $row['in'],
                'balance' => round($row['balance'], 2),
                'attachment_url' => $row['entry']->attachment ? Storage::disk('public')->url($row['entry']->attachment) : null,
            ])->values(),
        ])->values()]);
    }

    public static function rentTransactions(Tenant $tenant)
    {
        return PaymentTransaction::where('purpose', 'rent')->where('payable_type', Tenant::class)->where('payable_id', $tenant->id);
    }

    /** An invoice from TenantAccount (with `paid` summed). */
    public static function invoice(Invoice $i): array
    {
        $paid = (float) ($i->paid ?? 0);
        $unit = $i->lease?->unit;

        return [
            'id' => $i->id,
            'number' => $i->number,
            'type' => $i->type,
            'title' => $i->title,
            'unit' => $unit ? trim(($unit->estate?->name ? $unit->estate->name.' · ' : '').$unit->name) : null,
            'invoice_date' => $i->invoice_date?->toDateString(),
            'due_date' => $i->due_date?->toDateString(),
            'amount' => (float) $i->amount,
            'paid' => round($paid, 2),
            'balance' => round(max(0, (float) $i->amount - $paid), 2),
            'status' => $i->status,
            'overdue' => $i->isOverdue(),
        ];
    }

    public static function payment(Payment $p): array
    {
        return [
            'id' => $p->id,
            'number' => $p->number,
            'amount' => (float) $p->amount,
            'payment_date' => $p->payment_date?->toDateString(),
            'method' => ucwords(str_replace('_', ' ', (string) $p->payment_method)),
            'reference' => $p->reference,
            'status' => $p->status,
            'for' => $p->relationLoaded('allocations') && $p->allocations->isNotEmpty()
                ? $p->allocations->map(fn ($a) => $a->invoice?->number)->filter()->join(', ')
                : ($p->status === 'unallocated' ? 'Advance payment' : null),
        ];
    }

    public static function lease(Lease $l): array
    {
        return [
            'id' => $l->id,
            'number' => $l->number,
            'unit' => $l->unit ? trim(($l->unit->estate?->name ? $l->unit->estate->name.' · ' : '').$l->unit->name) : null,
            'unit_code' => $l->unit?->code,
            'status' => $l->status,
            'start_date' => $l->start_date?->toDateString(),
            'end_date' => $l->end_date?->toDateString(),
            'rent_amount' => (float) $l->rent_amount,
            'billing_cycle' => $l->billing_cycle,
            'security_deposit' => (float) $l->security_deposit,
        ];
    }
}
