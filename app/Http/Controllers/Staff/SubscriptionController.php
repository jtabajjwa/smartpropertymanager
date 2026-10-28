<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Payments\PaymentException;
use App\Payments\PaymentService;
use App\Services\SubscriptionAccess;
use App\Services\SubscriptionBilling;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff overview and management of every landlord's Primora subscription.
 */
class SubscriptionController extends Controller
{
    /** Landlord columns that make up "the subscription" (for its change history). */
    protected const FIELDS = ['subscription_status', 'plan_id', 'billing_cycle', 'max_units', 'current_period_end', 'grace_ends_at'];

    public function __construct(protected SubscriptionBilling $billing)
    {
    }

    public function index(Request $request)
    {
        $query = $this->withSubscriptionTotals(Landlord::query()->with('plan'));

        $this->filterByState($query, (string) $request->input('state'));

        if ($planId = $request->input('plan')) {
            $planId === 'none' ? $query->whereNull('plan_id') : $query->where('plan_id', $planId);
        }

        if ($search = trim((string) $request->input('search'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('trading_name', 'like', "%{$search}%")
                ->orWhere('contact_email', 'like', "%{$search}%")
                ->orWhere('contact_phone', 'like', "%{$search}%"));
        }

        // Soonest renewal first; landlords with no paid-until date last.
        $landlords = $query->orderByRaw('current_period_end IS NULL')->orderBy('current_period_end')->orderBy('name')
            ->paginate(25)->withQueryString();

        return view('staff.subscriptions.index', [
            'landlords' => $landlords,
            'stats' => $this->stats(),
            'plans' => Plan::orderBy('name')->get(['id', 'name']),
            'trialDays' => SubscriptionAccess::trialDays(),
            'graceDays' => SubscriptionAccess::graceDays(),
        ]);
    }

    /** How long new trials last, and the grace before an ended trial or plan goes read-only. */
    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'trial_days' => ['required', 'integer', 'min:1', 'max:365'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:90'],
        ]);

        Settings::set(SubscriptionAccess::TRIAL_DAYS_KEY, $data['trial_days']);
        Settings::set(SubscriptionAccess::GRACE_DAYS_KEY, $data['grace_days']);

        return back()->with('success', 'Trial and grace settings saved. New trials last '.$data['trial_days'].' days.');
    }

    public function show(Landlord $landlord)
    {
        $landlord = $this->withSubscriptionTotals(Landlord::query()->with('plan'))->findOrFail($landlord->id);
        $units = $landlord->units_count;

        $payments = PaymentTransaction::forLandlord($landlord->id)->where('purpose', 'subscription')
            ->with(['initiatedByUser', 'initiatedByStaffUser'])->latest('id')->get();

        // Changes to the subscription fields, newest first, with who/why.
        $history = Audit::with(['user', 'staffUser'])
            ->where('auditable_type', Landlord::class)->where('auditable_id', $landlord->id)
            ->where('action', 'updated')->latest('id')->limit(100)->get()
            ->filter(fn (Audit $a) => array_intersect(array_keys($a->new_values ?? []), self::FIELDS))
            ->take(30);

        $plans = $this->billing->availablePlans();
        // Unit tiers per plan for the offline-payment form (smaller than current units: not allowed).
        $tierOptions = $plans->mapWithKeys(fn (Plan $plan) => [$plan->id => [
            'tiered' => $plan->isTiered(),
            'tiers' => $this->billing->tierOptions($plan, $units),
            'default_tier' => $this->billing->defaultTierId($plan, $units, $landlord->max_units),
        ]]);

        return view('staff.subscriptions.show', [
            'landlord' => $landlord,
            'units' => $units,
            'payments' => $payments,
            'history' => $history,
            'plans' => $plans,
            'allPlans' => Plan::orderBy('name')->get(['id', 'name', 'is_active']),
            'tierOptions' => $tierOptions,
            'unitLimitRemaining' => app(\App\Services\UnitLimit::class)->remaining($landlord),
            'monthlyValue' => $this->billing->monthlyValue($landlord, $units),
            'planNames' => Plan::pluck('name', 'id'),
        ]);
    }

    /** Directly adjust the subscription (trial end, goodwill extension, cancellation, corrections). */
    public function update(Request $request, Landlord $landlord)
    {
        $data = $request->validate([
            'subscription_status' => ['required', Rule::in(array_keys(SubscriptionBilling::STATUSES))],
            'plan_id' => ['nullable', 'exists:plans,id'],
            'billing_cycle' => ['nullable', Rule::in(array_keys(SubscriptionBilling::CYCLES))],
            'current_period_end' => ['nullable', 'date'],
            'max_units' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ], [
            'reason.required' => 'Please give a reason for this change; it is kept in the subscription history.',
        ]);

        $reason = $data['reason'];
        unset($data['reason']);

        $landlord->fill($data);

        if (!$landlord->isDirty()) {
            return back()->with('success', 'Nothing changed.');
        }

        $landlord->withAuditNote('Adjusted by staff: '.$reason)->save();

        return back()->with('success', 'Subscription updated.');
    }

    /** Money received outside Yo! (cash, bank transfer…): extends the subscription like a gateway payment. */
    public function recordPayment(Request $request, Landlord $landlord, PaymentService $payments)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'cycle' => ['required', Rule::in(array_keys(SubscriptionBilling::CYCLES))],
            'tier_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'min:1', 'max:99999999'],
            'method' => ['required', Rule::in(array_keys(PaymentService::OFFLINE_METHODS))],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'payer_phone' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $plan = Plan::with('tiers')->findOrFail($data['plan_id']);
        $units = $this->billing->unitCount($landlord);
        $tierId = $data['tier_id'] ?? $this->billing->defaultTierId($plan, $units, $landlord->max_units);
        $quote = $this->billing->quoteForTier($plan, $tierId, $data['cycle'], $units);

        if (!$quote) {
            return back()->withInput()->with('error', "That plan/tier can't be used for {$landlord->name}: it must cover at least the {$units} units they already have.");
        }

        try {
            $transaction = $payments->recordOffline(
                purpose: 'subscription',
                landlord: $landlord,
                amount: (float) $data['amount'],
                method: $data['method'],
                receivedAt: \Carbon\Carbon::parse($data['received_on']),
                context: [
                    'plan_id' => $plan->id,
                    'plan_name' => $plan->name,
                    'cycle' => $data['cycle'],
                    'units' => $units,
                    'tier' => $quote['tier'],
                    'tier_id' => $quote['tier_id'],
                    'max_units' => $quote['max_units'],
                    'list_price' => $quote['amount'],
                ],
                reference: $data['reference'] ?? null,
                payerPhone: $data['payer_phone'] ?? null,
                note: $data['note'] ?? null,
                payable: $landlord,
                recordedBy: $request->user('staff'),
            );
        } catch (PaymentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $until = $landlord->fresh()->current_period_end?->format('d M Y');

        return back()->with('success', "Payment {$transaction->reference} recorded. Subscription active until {$until}.");
    }

    // ------------------------------------------------------------------

    /** Units managed, and subscription money received, for each landlord. */
    protected function withSubscriptionTotals(Builder $query): Builder
    {
        $succeeded = fn ($q) => $q->where('purpose', 'subscription')->where('status', PaymentTransaction::SUCCEEDED);

        return $query
            ->withCount('units')
            ->withSum(['paymentTransactions as subscription_paid' => $succeeded], 'amount')
            ->withMax(['paymentTransactions as last_paid_at' => $succeeded], 'completed_at');
    }

    /** Same meaning as Landlord::subscriptionState(), in SQL. */
    protected function filterByState(Builder $query, string $state): void
    {
        $today = today()->toDateString();
        $soon = today()->addDays(Landlord::EXPIRING_WITHIN_DAYS)->toDateString();

        match ($state) {
            'active' => $query->where('subscription_status', 'active')->where(fn ($q) => $q->whereNull('current_period_end')->orWhere('current_period_end', '>=', $today)),
            'expiring' => $query->where('subscription_status', 'active')->whereBetween('current_period_end', [$today, $soon]),
            'expired' => $query->where(fn ($q) => $q->where('subscription_status', 'past_due')
                ->orWhere(fn ($q) => $q->whereIn('subscription_status', ['active', 'trialing'])->where('current_period_end', '<', $today))),
            'trial' => $query->where('subscription_status', 'trialing')->where(fn ($q) => $q->whereNull('current_period_end')->orWhere('current_period_end', '>=', $today)),
            'cancelled' => $query->where('subscription_status', 'cancelled'),
            default => null,
        };
    }

    protected function stats(): array
    {
        $landlords = Landlord::with('plan.tiers')->withCount('units')->get();

        $states = $landlords->countBy(fn (Landlord $l) => $l->subscriptionState());

        $mrr = $landlords
            ->filter(fn (Landlord $l) => in_array($l->subscriptionState(), ['active', 'expiring'], true))
            ->sum(fn (Landlord $l) => $this->billing->monthlyValue($l, $l->units_count) ?? 0);

        return [
            'active' => ($states['active'] ?? 0) + ($states['expiring'] ?? 0),
            'trial' => $states['trial'] ?? 0,
            'expiring' => $states['expiring'] ?? 0,
            'expired' => ($states['expired'] ?? 0) + ($states['past_due'] ?? 0) + ($states['trial_ended'] ?? 0),
            'mrr' => $mrr,
            'collected_month' => (float) PaymentTransaction::where('purpose', 'subscription')->where('status', PaymentTransaction::SUCCEEDED)
                ->where('completed_at', '>=', now()->startOfMonth())->sum('amount'),
        ];
    }
}
