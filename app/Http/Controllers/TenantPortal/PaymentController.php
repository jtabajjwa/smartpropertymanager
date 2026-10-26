<?php

namespace App\Http\Controllers\TenantPortal;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Models\Tenant;
use App\Payments\Msisdn;
use App\Payments\PaymentException;
use App\Payments\PaymentService;
use App\Services\CollectionFees;
use App\Services\TenantPortal\TenantAccount;
use Illuminate\Http\Request;

/**
 * A tenant paying an invoice, or paying in advance, by mobile money
 * (Yo! Payments, into the Primora pool) — for landlords Primora collects for.
 */
class PaymentController extends Controller
{
    public const MIN_AMOUNT = 500;
    public const MAX_AMOUNT = 5_000_000;

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

    public function store(Request $request, CollectionFees $fees, PaymentService $payments)
    {
        $tenant = $request->attributes->get('tenant');
        $landlord = $tenant->landlord;

        if (!$landlord->collect_payments) {
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

        $msisdn = Msisdn::normalize($data['phone']);
        if (!$msisdn) {
            return back()->withInput()->withErrors(['phone' => 'Enter a Ugandan mobile money number, e.g. 0772 123 456.']);
        }

        $principal = round((float) $data['amount']);
        $invoice = null;

        if (!empty($data['invoice_id'])) {
            $invoice = (new TenantAccount($tenant))->findOpenInvoice((int) $data['invoice_id']);

            if (!$invoice) {
                return back()->withInput()->with('error', 'That invoice is already paid or no longer available.');
            }
            if ($principal > $invoice->balance) {
                return back()->withInput()->withErrors(['amount' => 'That is more than the UGX '.number_format($invoice->balance).' still due on this invoice. To pay extra, make an advance payment.']);
            }
        }

        $waiting = PaymentTransaction::where('purpose', 'rent')->where('payable_type', Tenant::class)->where('payable_id', $tenant->id)
            ->open()->where('created_at', '>', now()->subMinutes(3))->exists();
        if ($waiting) {
            return back()->withInput()->with('error', 'A payment request is already waiting for approval on a phone. Approve or decline it first, or try again in a few minutes.');
        }

        $quote = $fees->quote($landlord, $principal);

        if ($quote['charge'] > self::MAX_AMOUNT) {
            return back()->withInput()->withErrors(['amount' => 'With the collection fee that is more than UGX '.number_format(self::MAX_AMOUNT).'. Please pay a smaller amount.']);
        }

        try {
            $transaction = $payments->initiate(
                purpose: 'rent',
                landlord: $landlord,
                amount: $quote['charge'],
                msisdn: $msisdn,
                context: [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'invoice_id' => $invoice?->id,
                    'invoice_number' => $invoice?->number,
                    'principal' => $quote['principal'],
                    'fee' => $quote['fee'],
                    'fee_bearer' => $quote['bearer'],
                ],
                payable: $tenant,
            );
        } catch (PaymentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $transaction->update(['fee_amount' => $quote['fee'], 'fee_bearer' => $quote['bearer'], 'landlord_amount' => $quote['landlord_amount']]);

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
