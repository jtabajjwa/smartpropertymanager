<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Payments\PaymentService;
use App\Services\CheckoutException;
use App\Services\CurrentLandlord;
use App\Services\PartnerProgram;
use App\Services\SubscriptionBilling;
use App\Services\SubscriptionCheckout;
use App\Services\UnitLimit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The landlord's Primora plan: what they have, what they can choose, and paying by mobile money (as the web's Subscription page). */
class SubscriptionController extends Controller
{
    public function __construct(protected SubscriptionCheckout $checkout, protected SubscriptionBilling $billing)
    {
    }

    public function show(Request $request, PaymentService $payments, PartnerProgram $program)
    {
        $landlord = Landlord::with('plan')->findOrFail(CurrentLandlord::id());
        $units = $this->billing->unitCount($landlord);

        // Each tier's price per cycle, with what the landlord pays after their promotion.
        $plans = $this->checkout->plans($landlord, $units)->map(function ($plan) use ($landlord, $program) {
            $plan['tiers'] = collect($plan['tiers'])->map(function ($tier) use ($plan, $landlord, $program) {
                $tier['pay'] = collect($tier['prices'])->map(function ($amount, $cycle) use ($plan, $landlord, $program) {
                    $d = $program->discountFor($landlord, $plan['id'], $cycle, (float) $amount);

                    return ['list' => (float) $amount, 'amount' => (float) ($d['amount'] ?? $amount), 'discount' => (float) ($d['discount'] ?? 0)];
                })->all();

                return $tier;
            })->all();

            return $plan;
        });

        $history = PaymentTransaction::forLandlord($landlord->id)->where('purpose', 'subscription')->latest()->limit(20)->get();
        $awaiting = $history->first(fn ($p) => $p->isOpen() && $p->created_at->gt(now()->subMinutes(15)));

        return response()->json([
            'current' => [
                'plan_id' => $landlord->plan_id,
                'plan' => $landlord->plan?->name,
                'cycle' => $landlord->billing_cycle,
                'cycle_label' => $landlord->billing_cycle ? SubscriptionBilling::cycleLabel($landlord->billing_cycle) : null,
                'state' => $landlord->subscriptionState(),
                'access_level' => $landlord->accessLevel(),
                'read_only' => $landlord->isReadOnly(),
                'period_end' => $landlord->current_period_end?->toDateString(),
                'max_units' => $landlord->max_units,
                'units' => $units,
                'remaining' => app(UnitLimit::class)->remaining($landlord),
            ],
            'plans' => $plans->values(),
            'cycles' => collect(SubscriptionBilling::CYCLES)->map(fn ($c, $key) => ['key' => $key, 'label' => $c['label'], 'months' => $c['months']])->values(),
            'promo' => $this->checkout->promo($landlord),
            'can_take_code' => $program->canTakeCode($landlord),
            'available' => $payments->gatewayAvailable(),
            'default_phone' => $request->user()->phone ?? $landlord->contact_phone,
            'awaiting' => $awaiting ? $this->payment($awaiting) : null,
            'payments' => $history->map(fn ($p) => $this->payment($p))->values(),
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

        try {
            $t = $this->checkout->start(Landlord::findOrFail(CurrentLandlord::id()), $request->user(), (int) $data['plan_id'], $data['cycle'], $data['phone'],
                isset($data['tier_id']) ? (int) $data['tier_id'] : null);
        } catch (CheckoutException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        return response()->json([
            'reference' => $t->reference,
            'status' => $t->status,
            'message' => $t->status === PaymentTransaction::SUCCEEDED ? 'Payment received. Your subscription is active.' : 'Approve the payment on '.$t->displayMsisdn().'.',
        ], 201);
    }

    public function code(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:30']]);

        try {
            $message = $this->checkout->applyCode(Landlord::findOrFail(CurrentLandlord::id()), $data['code']);
        } catch (CheckoutException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        return response()->json(['message' => $message]);
    }

    protected function payment(PaymentTransaction $p): array
    {
        $c = $p->context ?? [];

        return [
            'reference' => $p->reference,
            'date' => $p->created_at->toIso8601String(),
            'plan' => $c['plan_name'] ?? null,
            'cycle' => isset($c['cycle']) ? SubscriptionBilling::cycleLabel($c['cycle']) : null,
            'tier' => $c['tier'] ?? null,
            'amount' => (float) $p->amount,
            'discount' => (float) ($c['discount'] ?? 0),
            'period_end' => $c['period_end'] ?? null,
            'paid_with' => $p->gateway === 'manual' ? (PaymentService::OFFLINE_METHODS[$c['method'] ?? ''] ?? 'Offline') : $p->displayMsisdn(),
            'status' => $p->status,
            'open' => $p->isOpen(),
            'message' => $p->status_message,
        ];
    }
}
