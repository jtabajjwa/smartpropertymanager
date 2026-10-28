<?php

namespace App\Http\Controllers\TenantPortal;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Models\Tenant;
use App\Payments\PaymentService;
use App\Services\CollectionFees;
use App\Services\TenantPortal\RentPaymentException;
use App\Services\TenantPortal\RentPayments;
use App\Services\TenantPortal\TenantAccount;
use Illuminate\Http\Request;

/**
 * A tenant paying an invoice, or paying in advance, by mobile money
 * (Yo! Payments, into the Primora pool) — for landlords Primora collects for.
 */
class PaymentController extends Controller
{
    public const MIN_AMOUNT = RentPayments::MIN_AMOUNT;
    public const MAX_AMOUNT = RentPayments::MAX_AMOUNT;

    public function create(Request $request, CollectionFees $fees, PaymentService $payments)
    {
        $tenant = $request->attributes->get('tenant');
        $account = new TenantAccount($tenant);
        $open = $account->openInvoices();

        $selected = $request->has('invoice') ? $open->firstWhere('id', (int) $request->input('invoice')) : null;

        return view('tenant-portal.pay', [
            'landlord' => $tenant->landlord,
            'enabled' => $tenant->landlord->collect_payments && $payments->gatewayAvailable(),
            'openInvoices' => $open,
            'selected' => $selected ?? ($request->input('for') === 'advance' ? null : $open->first()),
            'advance' => $request->input('for') === 'advance' || $open->isEmpty(),
            'tiers' => $fees->forScript(),
            'passFee' => (bool) $tenant->landlord->pass_collection_fee,
            'defaultPhone' => $tenant->primary_phone,
            'credit' => $account->credit(),
        ]);
    }

    public function store(Request $request, RentPayments $rent)
    {
        $tenant = $request->attributes->get('tenant');

        if (!$tenant->landlord->collect_payments) {
            return redirect()->route('tenant.pay')->with('error', "Your landlord doesn't accept mobile money payments through Primora yet.");
        }

        $data = $request->validate([
            'invoice_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'min:'.self::MIN_AMOUNT, 'max:'.self::MAX_AMOUNT],
            'phone' => ['required', 'string', 'max:20'],
        ], [
            'amount.min' => 'The smallest amount you can pay by mobile money is UGX '.number_format(self::MIN_AMOUNT).'.',
            'amount.max' => 'The most you can pay at once is UGX '.number_format(self::MAX_AMOUNT).'.',
        ]);

        try {
            $invoiceId = !empty($data['invoice_id']) ? (int) $data['invoice_id'] : null;
            $transaction = $rent->start($tenant, $invoiceId, (float) $data['amount'], $data['phone']);
        } catch (RentPaymentException $e) {
            return $e->field
                ? back()->withInput()->withErrors([$e->field => $e->getMessage()])
                : back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('tenant.dashboard')->with('success', $transaction->status === PaymentTransaction::SUCCEEDED
            ? 'Payment received. Thank you!'
            : 'Payment request sent to '.$transaction->displayMsisdn().'. Approve it on the phone with your PIN.');
    }

    /** Polled while a payment awaits approval; nudges a gateway status check at most every 5 seconds. */
    public function status(Request $request, string $reference, PaymentService $payments)
    {
        $tenant = $request->attributes->get('tenant');

        $transaction = PaymentTransaction::where('purpose', 'rent')->where('payable_type', Tenant::class)
            ->where('payable_id', $tenant->id)->where('reference', $reference)->firstOrFail();

        if ($transaction->isOpen() && (!$transaction->last_checked_at || $transaction->last_checked_at->lt(now()->subSeconds(5)))) {
            try {
                $transaction = $payments->refresh($transaction);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'reference' => $transaction->reference,
            'status' => $transaction->status,
            'message' => $transaction->status_message,
            'receipt' => $transaction->network_reference ?: $transaction->receipt_number,
        ]);
    }
}
