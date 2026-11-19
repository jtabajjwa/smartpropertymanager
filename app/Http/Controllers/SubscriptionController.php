<?php

namespace App\Http\Controllers;

use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Payments\Msisdn;
use App\Payments\PaymentException;
use App\Payments\PaymentService;
use App\Services\CurrentLandlord;
use App\Services\SubscriptionBilling;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A landlord viewing and paying for their Primora subscription by mobile money.
 */
class SubscriptionController extends Controller
{
    public function __construct(protected SubscriptionBilling $billing, protected PaymentService $payments)
    {
    }

    public function show(\App\Services\SubscriptionCheckout $checkout)
    {
        $landlord = Landlord::with('plan')->findOrFail(CurrentLandlord::id());
        $units = $this->billing->unitCount($landlord);

        $payments = PaymentTransaction::forLandlord($landlord->id)
            ->where('purpose', 'subscription')
            ->latest()
            ->limit(20)
            ->get();

        // A payment started in the last 15 minutes that is still awaiting approval.
        $awaiting = $payments->first(fn ($p) => $p->isOpen() && $p->created_at->gt(now()->subMinutes(15)));

        return view('subscription.show', [
            'promo' => $checkout->promo($landlord),
            'canTakeCode' => app(\App\Services\PartnerProgram::class)->canTakeCode($landlord),
            'landlord' => $landlord,
            'units' => $units,
            'plans' => $checkout->plans($landlord, $units),
            'cycles' => SubscriptionBilling::CYCLES,
            'payments' => $payments,
            'awaiting' => $awaiting,
            'available' => $this->payments->gatewayAvailable(),
            'defaultPhone' => auth()->user()->phone ?? $landlord->contact_phone,
            'remaining' => app(\App\Services\UnitLimit::class)->remaining($landlord),
        ]);
    }

    public function pay(Request $request, \App\Services\SubscriptionCheckout $checkout)
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('is_active', true)],
            'cycle' => ['required', Rule::in(array_keys(SubscriptionBilling::CYCLES))],
            'phone' => ['required', 'string', 'max:20'],
            'tier_id' => ['nullable', 'integer'],
        ]);

        try {
            $transaction = $checkout->start(Landlord::findOrFail(CurrentLandlord::id()), auth()->user(), (int) $data['plan_id'], $data['cycle'], $data['phone'],
                isset($data['tier_id']) ? (int) $data['tier_id'] : null);
        } catch (\App\Services\CheckoutException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        if ($transaction->status === PaymentTransaction::SUCCEEDED) {
            return redirect()->route('subscription.show')->with('success', 'Payment received. Your subscription is active.');
        }

        return redirect()->route('subscription.show')
            ->with('success', 'Payment request sent to '.$transaction->displayMsisdn().'. Approve it on the phone.');
    }

    /** A partner or promo code added before the first paid subscription. */
    public function applyCode(Request $request, \App\Services\SubscriptionCheckout $checkout)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:30']]);

        try {
            $message = $checkout->applyCode(Landlord::findOrFail(CurrentLandlord::id()), $data['code']);
        } catch (\App\Services\CheckoutException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message);
    }

}
