<?php

namespace App\Services;

use App\Models\Landlord;
use App\Models\PartnerCode;
use App\Models\PaymentTransaction;
use App\Models\PromotionRedemption;
use App\Models\StaffUser;

/**
 * Partners and promotions, phase 1:
 *
 *  - Codes: a partner code (e.g. JOHN24), a campaign code, or the agent's
 *    short number (e.g. 4821, standing for their default code).
 *  - Attribution: one partner per landlord, linked at sign-up (code or the
 *    partner signing them up) or by staff; a code can only be added before
 *    the first paid subscription, which locks the link.
 *  - Discounts: the landlord's promotion takes money off their first paid
 *    periods; each discounted payment is recorded (PromotionRedemption) and
 *    posted to the books (Primora's share as Promotional discounts, the
 *    partner's share against what Primora owes them).
 */
class PartnerProgram
{
    public const SOURCES = ['code' => 'Code at sign-up', 'partner' => 'Signed up by the partner', 'staff' => 'Assigned by staff', 'payment' => 'Code when paying'];

    /** A usable code: active, of an active partner (or a Primora campaign code). Digits only = an agent number. */
    public function resolve(?string $input): ?PartnerCode
    {
        $code = PartnerCode::normalize((string) $input);
        if ($code === '' || mb_strlen($code) > 30) {
            return null;
        }

        $found = ctype_digit($code)
            ? PartnerCode::query()->where('is_default', true)->whereHas('partner', fn ($q) => $q->where('number', $code))->first()
            : PartnerCode::query()->where('code', $code)->first();

        if (!$found || !$found->is_active || ($found->partner_id && !$found->partner?->isActive())) {
            return null;
        }

        return $found;
    }

    /** Can a code still be added to this landlord? Only before they're linked, and before their first paid subscription. */
    public function canTakeCode(Landlord $landlord): bool
    {
        return !$landlord->partner_id && !$landlord->promotion_id && !$landlord->partner_locked_at
            && !PaymentTransaction::forLandlord($landlord->id)->where('purpose', 'subscription')->where('status', PaymentTransaction::SUCCEEDED)->exists();
    }

    /** Link a landlord to the code's partner (if any) and give them its promotion (if running). */
    public function attach(Landlord $landlord, PartnerCode $code, string $source, ?string $note = null): void
    {
        $promotion = $code->effectivePromotion();

        $landlord->withAuditNote(trim('Partner code '.$code->code.' ('.(self::SOURCES[$source] ?? $source).')'.($note ? ": {$note}" : '')))->update([
            'partner_id' => $code->partner_id ?? $landlord->partner_id,
            'partner_code_id' => $code->id,
            'promotion_id' => $promotion && $promotion->isRunning($landlord) ? $promotion->id : $landlord->promotion_id,
            'partner_source' => $code->partner_id ? $source : $landlord->partner_source,
            'partner_attributed_at' => $code->partner_id ? now() : $landlord->partner_attributed_at,
        ]);
    }

    /** Staff moving a landlord to another partner (or none), with a reason; a running promotion stays. */
    public function reassign(Landlord $landlord, ?PartnerCode $code, StaffUser $by, string $reason): void
    {
        $landlord->withAuditNote("Partner changed by {$by->name}: {$reason}")->update([
            'partner_id' => $code?->partner_id,
            'partner_code_id' => $code?->id,
            'partner_source' => $code ? 'staff' : null,
            'partner_attributed_at' => $code ? now() : null,
            'promotion_id' => $landlord->promotion_id ?? ($code?->effectivePromotion()?->isRunning($landlord) ? $code->effectivePromotion()->id : null),
        ]);
    }

    /**
     * The discount on a subscription payment, if the landlord's promotion
     * applies to this plan and cycle and they have discounted periods left.
     *
     * @return array{promotion_id: int, promotion: string, list_amount: float, discount: float, amount: float, partner_share: float, primora_share: float, period_number: int}|null
     */
    public function discountFor(Landlord $landlord, int $planId, string $cycle, float $amount): ?array
    {
        $promotion = $landlord->promotion;
        if (!$promotion || !$promotion->isRunning($landlord) || !$promotion->appliesTo($planId, $cycle)
            || $landlord->promotion_periods_used >= $promotion->periods) {
            return null;
        }

        $discount = $promotion->discountOn($amount);
        if ($discount <= 0) {
            return null;
        }
        $partnerShare = $promotion->partnerShareOf($discount, (bool) $landlord->partner_id);

        return [
            'promotion_id' => $promotion->id,
            'promotion' => $promotion->name,
            'list_amount' => round($amount, 2),
            'discount' => $discount,
            'amount' => round($amount - $discount, 2),
            'partner_share' => $partnerShare,
            'primora_share' => round($discount - $partnerShare, 2),
            'period_number' => $landlord->promotion_periods_used + 1,
        ];
    }

    /** A subscription payment succeeded: count the discounted period, record it, and lock the partner link. */
    public function paymentSucceeded(Landlord $landlord, PaymentTransaction $transaction): void
    {
        $c = $transaction->context ?? [];
        $updates = [];

        if (!empty($c['promotion_id']) && (float) ($c['discount'] ?? 0) > 0
            && !PromotionRedemption::where('payment_transaction_id', $transaction->id)->exists()) {
            PromotionRedemption::create([
                'promotion_id' => $c['promotion_id'],
                'landlord_id' => $landlord->id,
                'partner_id' => $landlord->partner_id,
                'partner_code_id' => $landlord->partner_code_id,
                'payment_transaction_id' => $transaction->id,
                'period_number' => (int) ($c['period_number'] ?? $landlord->promotion_periods_used + 1),
                'list_amount' => (float) ($c['list_amount'] ?? $transaction->amount),
                'discount_amount' => (float) $c['discount'],
                'primora_share' => (float) ($c['primora_share'] ?? $c['discount']),
                'partner_share' => (float) ($c['partner_share'] ?? 0),
            ]);
            $updates['promotion_periods_used'] = $landlord->promotion_periods_used + 1;
        }

        if ($landlord->partner_id && !$landlord->partner_locked_at) {
            $updates['partner_locked_at'] = now();
        }

        if ($updates) {
            $landlord->update($updates);
        }

        // The partner's revenue share on this payment.
        app(PartnerCommissions::class)->accrue($transaction->fresh());
    }
}
