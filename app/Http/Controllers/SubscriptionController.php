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

    public function show()
    {
        $landlord = Landlord::with('plan')->findOrFail(CurrentLandlord::id());
        $units = $this->billing->unitCount($landlord);

        // Every plan with its unit tiers; tiers smaller than what the landlord
        // already has are listed but not selectable.
        $plans = $this->billing->availablePlans()->map(fn (Plan $plan) => [
            'id' => $plan->id,
            'name' => $plan->name,
            'tiered' => $plan->isTiered(),
            'tiers' => $this->billing->tierOptions($plan, $units),
            'default_tier' => $this->billing->defaultTierId($plan, $units, $landlord->max_units),
        ])->filter(fn ($p) => collect($p['tiers'])->contains(fn ($t) => $t['eligible'] && $t['prices']))->values();

        $payments = PaymentTransaction::forLandlord($landlord->id)
            ->where('purpose', 'subscription')
            ->latest()
            ->limit(20)
            ->get();

        // A payment started in the last 15 minutes that is still awaiting approval.
        $awaiting = $payments->first(fn ($p) => $p->isOpen() && $p->created_at->gt(now()->subMinutes(15)));

        return view('subscription.show', [
            'landlord' => $landlord,
            'units' => $units,
            'plans' => $plans,
            'cycles' => SubscriptionBilling::CYCLES,
            'payments' => $payments,
            'awaiting' => $awaiting,
            'available' => $this->payments->gatewayAvailable(),
            'defaultPhone' => auth()->user()->phone ?? $landlord->contact_phone,
            'remaining' => app(\App\Services\UnitLimit::class)->remaining($landlord),
        ]);
    }

    public function pay(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('is_active', true)],
            'cycle' => ['required', Rule::in(array_keys(SubscriptionBilling::CYCLES))],
            'phone' => ['required', 'string', 'max:20'],
            'tier_id' => ['nullable', 'integer'],
        ]);

        $msisdn = Msisdn::normalize($data['phone']);
        if (!$msisdn) {
            return back()->withInput()->withErrors(['phone' => 'Enter a Ugandan mobile money number, e.g. 0772 123 456.']);
        }

        $landlord = Landlord::findOrFail(CurrentLandlord::id());

        // Don't send a second prompt while one is still waiting on the phone.
        $recent = PaymentTransaction::forLandlord($landlord->id)->where('purpose', 'subscription')
            ->open()->where('created_at', '>', now()->subMinutes(3))->exists();
        if ($recent) {
            return back()->with('error', 'A payment request is already waiting for approval on a phone. Approve or decline it first, or try again in a few minutes.');
        }

        $plan = Plan::with('tiers')->findOrFail($data['plan_id']);
        $units = $this->billing->unitCount($landlord);
        $tierId = $data['tier_id'] ?? $this->billing->defaultTierId($plan, $units, $landlord->max_units);
        $quote = $this->billing->quoteForTier($plan, $tierId, $data['cycle'], $units);

        if (!$quote) {
            $chosen = $plan->tiers->firstWhere('id', $tierId);
            $message = $chosen && $chosen->max_units !== null && $chosen->max_units < $units
                ? "That tier covers up to {$chosen->max_units} units, but you already have {$units}. Please choose a larger tier."
                : "That plan and tier can't be paid for {$units} units. Please choose another, or contact Primora.";

            return back()->withInput()->with('error', $message);
        }

        try {
            $transaction = $this->payments->initiate(
                purpose: 'subscription',
                landlord: $landlord,
                amount: $quote['amount'],
                msisdn: $msisdn,
                context: [
                    'plan_id' => $plan->id,
                    'plan_name' => $plan->name,
                    'cycle' => $data['cycle'],
                    'units' => $units,
                    'tier' => $quote['tier'],
                    'tier_id' => $quote['tier_id'],
                    'max_units' => $quote['max_units'],
                ],
                payable: $landlord,
                initiator: auth()->user(),
            );
        } catch (PaymentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        if ($transaction->status === PaymentTransaction::SUCCEEDED) {
            return redirect()->route('subscription.show')->with('success', 'Payment received. Your subscription is active.');
        }

        return redirect()->route('subscription.show')
            ->with('success', 'Payment request sent to '.$transaction->displayMsisdn().'. Approve it on the phone.');
    }

}
