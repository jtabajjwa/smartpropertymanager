<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewayLog;
use App\Models\PaymentTransaction;
use App\Payments\PaymentService;

/**
 * Staff view of every collection through the payment gateway(s): the list,
 * one payment's details with its full gateway log, and callbacks that matched
 * no payment (useful when reconciling the pool account).
 */
class PaymentController extends Controller
{
    public function index()
    {
        $query = PaymentTransaction::with('landlord')->latest('id');

        if ($status = request('status')) {
            $query->where('status', $status);
        }
        if ($purpose = request('purpose')) {
            $query->where('purpose', $purpose);
        }
        if ($gateway = request('gateway')) {
            $query->where('gateway', $gateway);
        }
        if ($from = request('from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = request('to')) {
            $query->whereDate('created_at', '<=', $to);
        }
        if ($search = trim((string) request('search'))) {
            $digits = preg_replace('/\D/', '', $search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('gateway_reference', 'like', "%{$search}%")
                    ->orWhere('network_reference', 'like', "%{$search}%")
                    ->orWhere('receipt_number', 'like', "%{$search}%")
                    ->orWhere('narrative', 'like', "%{$search}%")
                    ->orWhereHas('landlord', fn ($l) => $l->where('name', 'like', "%{$search}%"));
                if (strlen($digits) >= 6) {
                    $q->orWhere('msisdn', 'like', '%'.ltrim($digits, '0').'%');
                }
            });
        }

        $payments = $query->paginate(25)->withQueryString();

        $succeeded = PaymentTransaction::where('status', PaymentTransaction::SUCCEEDED);
        $stats = [
            'today' => (float) (clone $succeeded)->whereDate('completed_at', today())->sum('amount'),
            'month' => (float) (clone $succeeded)->where('completed_at', '>=', now()->startOfMonth())->sum('amount'),
            'open' => PaymentTransaction::open()->count(),
            'failed_30d' => PaymentTransaction::where('status', PaymentTransaction::FAILED)->where('created_at', '>=', now()->subDays(30))->count(),
            'unmatched' => PaymentGatewayLog::whereNull('payment_transaction_id')->where('direction', PaymentGatewayLog::INBOUND)->count(),
        ];

        return view('staff.payments.index', [
            'payments' => $payments,
            'stats' => $stats,
            'purposes' => array_keys(config('payments.purposes', [])),
            'gateways' => PaymentTransaction::query()->distinct()->pluck('gateway'),
        ]);
    }

    public function show(PaymentTransaction $payment)
    {
        $payment->load(['landlord', 'gatewayLogs', 'initiatedByUser', 'initiatedByStaffUser']);

        return view('staff.payments.show', compact('payment'));
    }

    /** Ask the gateway for this payment's status right now. */
    public function check(PaymentTransaction $payment, PaymentService $payments)
    {
        if (!$payment->isOpen()) {
            return back()->with('success', 'This payment is already '.$payment->status.'; its status won\'t change.');
        }

        try {
            $before = $payment->status;
            $after = $payments->refresh($payment)->status;
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Could not check the status: '.$e->getMessage());
        }

        return back()->with('success', $after === $before
            ? "Checked with the gateway: still {$after}."
            : "Checked with the gateway: {$before} → {$after}.");
    }

    /** Callbacks from the gateway that didn't match any payment here. */
    public function unmatched()
    {
        $logs = PaymentGatewayLog::whereNull('payment_transaction_id')
            ->where('direction', PaymentGatewayLog::INBOUND)
            ->latest('id')
            ->paginate(25);

        return view('staff.payments.unmatched', compact('logs'));
    }
}
