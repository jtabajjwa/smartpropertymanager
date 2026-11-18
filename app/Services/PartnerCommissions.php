<?php

namespace App\Services;

use App\Accounting\BookKeeper;
use App\Accounting\Ledger;
use App\Models\CommissionPlan;
use App\Models\Landlord;
use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\PaymentTransaction;
use App\Models\StaffUser;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Partners' revenue share (phase 2).
 *
 * On every successful subscription payment by a partner's landlord:
 *   - share:    the partner's plan rate × the base (amount paid, or paid less
 *               the mobile money charge), for the plan's months from the
 *               landlord's first payment, then the later rate;
 *   - bonus:    the plan's one-off bonus, on the landlord's first payment;
 *   - discount: the part of a promotion the partner bears (negative; its
 *               books entry is part of the payment's own posting).
 * Withholding tax is taken when a line is earned. Lines are held for the
 * holding period, then available to pay out. Everything is set by staff:
 * plans, the default plan, holding days, minimum payout and the WHT rate.
 *
 * Books: Dr Partner commissions (gross); Cr Partner commissions payable (net,
 * per partner); Cr Withholding tax payable (tax).
 */
class PartnerCommissions
{
    public const HOLDING_DAYS = 'partners.holding_days';
    public const MIN_PAYOUT = 'partners.min_payout';
    public const WHT_PERCENT = 'partners.wht_percent';
    public const DEFAULT_PLAN = 'partners.default_commission_plan_id';

    public function __construct(protected Ledger $ledger)
    {
    }

    public static function holdingDays(): int
    {
        return max(0, Settings::int(self::HOLDING_DAYS, 14));
    }

    public static function minPayout(): float
    {
        return max(0.0, (float) Settings::get(self::MIN_PAYOUT, 20000));
    }

    public static function whtPercent(): float
    {
        return min(100.0, max(0.0, (float) Settings::get(self::WHT_PERCENT, 0)));
    }

    public static function defaultPlan(): ?CommissionPlan
    {
        $id = Settings::int(self::DEFAULT_PLAN, 0);

        return $id ? CommissionPlan::where('is_active', true)->find($id) : null;
    }

    /** The partner's plan, else the default; none = they earn nothing on payments. */
    public function planFor(Partner $partner): ?CommissionPlan
    {
        $plan = $partner->commissionPlan;

        return $plan && $plan->is_active ? $plan : self::defaultPlan();
    }

    /** A subscription payment succeeded: record what its landlord's partner earns. Safe to call twice. */
    public function accrue(PaymentTransaction $t): void
    {
        if ($t->purpose !== 'subscription' || $t->status !== PaymentTransaction::SUCCEEDED || !$t->landlord_id) {
            return;
        }
        $landlord = Landlord::with('partner.commissionPlan')->find($t->landlord_id);
        $partner = $landlord?->partner;
        if (!$partner) {
            return;
        }

        $c = $t->context ?? [];
        $when = $t->completed_at ?? now();
        $amount = (float) $t->amount;
        $lines = [];

        // A promotion's share the partner bears (already in the books with the payment).
        if ((int) ($c['partner_id'] ?? 0) === $partner->id && (float) ($c['partner_share'] ?? 0) > 0) {
            $lines[] = ['type' => 'discount', 'description' => 'Discount you bear: '.($c['promotion'] ?? 'promotion').' · '.$landlord->name,
                'gross' => -(float) $c['partner_share'], 'wht' => 0.0, 'immediate' => true, 'book' => false];
        }

        $plan = $this->planFor($partner);
        if ($plan) {
            $first = $landlord->partner_locked_at ?? $when;
            $monthsIn = (int) floor(max(0, $first->floatDiffInMonths($when)));
            $rate = $plan->rateFor($monthsIn);
            $base = $plan->base === 'paid_less_charges' && $t->gateway !== 'manual'
                ? max(0.0, $amount - app(CollectionFees::class)->feeFor($amount))
                : $amount;

            if ($rate > 0 && $base > 0) {
                $lines[] = ['type' => 'share', 'description' => "{$landlord->name} · subscription {$t->reference}", 'base' => $base, 'rate' => $rate,
                    'gross' => round($base * $rate / 100, 2), 'book' => true];
            }
            if ($plan->signup_bonus > 0 && !PartnerCommission::where('partner_id', $partner->id)->where('landlord_id', $landlord->id)->where('type', 'bonus')->exists()) {
                $lines[] = ['type' => 'bonus', 'description' => "{$landlord->name} · first payment", 'gross' => $plan->signup_bonus, 'book' => true];
            }
        }

        foreach ($lines as $line) {
            if (PartnerCommission::where('payment_transaction_id', $t->id)->where('type', $line['type'])->exists()) {
                continue;
            }
            $commission = $this->record($partner, $line + ['landlord_id' => $landlord->id, 'payment_transaction_id' => $t->id, 'earned_at' => $when]);
            if ($line['book']) {
                BookKeeper::attempt(fn () => $this->post($commission));
            }
        }
    }

    /** Staff: an amount added (+) or taken (−) with a reason; available straight away. */
    public function adjust(Partner $partner, float $amount, string $reason, StaffUser $by): PartnerCommission
    {
        return DB::transaction(function () use ($partner, $amount, $reason, $by) {
            $commission = $this->record($partner, ['type' => 'adjustment', 'description' => $reason, 'gross' => round($amount, 2), 'wht' => 0.0,
                'immediate' => true, 'created_by' => $by->id, 'earned_at' => now()]);
            $this->post($commission);

            return $commission;
        });
    }

    /** Staff: undo a line not yet paid out (e.g. the payment was refunded). */
    public function reverse(PartnerCommission $commission, string $reason, StaffUser $by): PartnerCommission
    {
        if ($commission->reversed_at || $commission->liquidation_id || $commission->type === 'reversal') {
            throw new \InvalidArgumentException('Only lines not yet paid out or reversed can be reversed.');
        }

        return DB::transaction(function () use ($commission, $reason, $by) {
            $reversal = PartnerCommission::create([
                'partner_id' => $commission->partner_id, 'landlord_id' => $commission->landlord_id, 'type' => 'reversal',
                'description' => 'Reversal: '.$commission->description.' ('.$reason.')',
                'gross' => -$commission->gross, 'wht_percent' => $commission->wht_percent, 'wht' => -$commission->wht, 'net' => -$commission->net,
                'earned_at' => now(), 'available_at' => now(), 'reverses_id' => $commission->id, 'reversed_at' => now(), 'created_by_staff_user_id' => $by->id,
            ]);
            $commission->update(['reversed_at' => now()]);
            if ($commission->type !== 'discount') {
                $this->post($reversal);
            } else {
                // The partner no longer bears that discount: Primora does.
                $this->ledger->post(now(), "Discount moved to Primora · {$commission->description}", [
                    ['account' => 'promo_discounts', 'debit' => -$commission->gross],
                    ['account' => 'partner_payable', 'credit' => -$commission->gross, 'partner_id' => $commission->partner_id],
                ], 'partner_commission', $reversal->id, 'reversal');
            }

            return $reversal;
        });
    }

    /**
     * What a partner has: on hold, available to withdraw, paid out, and
     * earned in total (all after tax).
     *
     * @return array{on_hold: float, available: float, paid: float, earned: float, wht: float}
     */
    public function balances(Partner $partner): array
    {
        $rows = PartnerCommission::where('partner_id', $partner->id)->get(['net', 'wht', 'available_at', 'liquidation_id', 'type']);
        $unpaid = $rows->whereNull('liquidation_id');

        return [
            'on_hold' => round($unpaid->filter(fn ($r) => $r->available_at->isFuture())->sum('net'), 2),
            'available' => round($unpaid->filter(fn ($r) => !$r->available_at->isFuture())->sum('net'), 2),
            'paid' => round($rows->whereNotNull('liquidation_id')->sum('net'), 2),
            'earned' => round($rows->sum('net'), 2),
            'wht' => round($rows->sum('wht'), 2),
        ];
    }

    // ------------------------------------------------------------------------------

    protected function record(Partner $partner, array $line): PartnerCommission
    {
        $gross = round((float) $line['gross'], 2);
        $whtPercent = array_key_exists('wht', $line) ? 0.0 : self::whtPercent();
        $wht = array_key_exists('wht', $line) ? (float) $line['wht'] : round($gross * $whtPercent / 100, 2);
        $earned = $line['earned_at'];

        return PartnerCommission::create([
            'partner_id' => $partner->id,
            'landlord_id' => $line['landlord_id'] ?? null,
            'payment_transaction_id' => $line['payment_transaction_id'] ?? null,
            'type' => $line['type'],
            'description' => mb_substr($line['description'], 0, 300),
            'base_amount' => $line['base'] ?? null,
            'rate_percent' => $line['rate'] ?? null,
            'gross' => $gross,
            'wht_percent' => $whtPercent,
            'wht' => $wht,
            'net' => round($gross - $wht, 2),
            'earned_at' => $earned,
            'available_at' => !empty($line['immediate']) ? $earned : $earned->copy()->addDays(self::holdingDays()),
            'created_by_staff_user_id' => $line['created_by'] ?? null,
        ]);
    }

    /** Dr Partner commissions (gross) / Cr Partner commissions payable (net) / Cr WHT payable; reversed for negative lines. */
    public function post(PartnerCommission $c): void
    {
        $partnerName = $c->partner?->name ?? 'partner';
        $lines = $c->gross >= 0
            ? [
                ['account' => 'partner_commissions', 'debit' => $c->gross, 'memo' => $c->typeLabel()],
                ['account' => 'partner_payable', 'credit' => $c->net, 'partner_id' => $c->partner_id, 'memo' => $partnerName],
                ['account' => 'wht_payable', 'credit' => $c->wht, 'memo' => $partnerName],
            ]
            : [
                ['account' => 'partner_payable', 'debit' => -$c->net, 'partner_id' => $c->partner_id, 'memo' => $partnerName],
                ['account' => 'wht_payable', 'debit' => -$c->wht, 'memo' => $partnerName],
                ['account' => 'partner_commissions', 'credit' => -$c->gross, 'memo' => $c->typeLabel()],
            ];

        $this->ledger->post($c->earned_at, "{$c->typeLabel()} · {$partnerName} · {$c->description}", $lines, 'partner_commission', $c->id, $c->type === 'reversal' ? 'reversal' : 'earned');
    }
}
