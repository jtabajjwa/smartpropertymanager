<?php

namespace App\Services;

use App\Accounting\BookKeeper;
use App\Accounting\Ledger;
use App\Accounting\Postings;
use App\Mail\NoticeMail;
use App\Models\Landlord;
use App\Models\Liquidation;
use App\Models\PaymentTransaction;
use App\Models\PayoutAccount;
use App\Models\StaffUser;
use App\Models\User;
use App\Payments\Contracts\PayoutGateway;
use App\Payments\GatewayResult;
use App\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Paying landlords the rent Primora collected for them.
 *
 *   landlord requests (to a verified payout account, within their available balance)
 *   → one staff member approves or rejects
 *   → a different staff member executes, choosing the route for bank payouts:
 *       through Yo! Payments (mobile money: acwithdrawfunds; bank: acwithdrawfundstobank
 *       to accounts registered on the Yo! account), confirmed by status checks; or
 *       a direct EFT from Primora's bank, made outside the system and recorded.
 *   → completed: posted to the books, landlord emailed.
 *
 * A payout attempt is committed as "processing" before anything is sent, and
 * an unclear answer is only ever resolved by a status check, never a resend.
 */
class Liquidations
{
    public const MIN_AMOUNT = 5000;

    /** Largest single mobile money payout (network limit). */
    public const MOBILE_MONEY_MAX = 5_000_000;

    /** After this long, "Yo! has no record of it" means the attempt never happened. */
    public const NOT_FOUND_AFTER_MINUTES = 30;

    public function __construct(
        protected Ledger $ledger,
        protected PayoutFees $fees,
        protected PaymentGatewayManager $gateways,
        protected Postings $postings,
    ) {
    }

    // --- Balances -----------------------------------------------------------

    /** Collected for the landlord and not yet paid out (from the books). */
    public function owed(int $landlordId): float
    {
        return $this->ledger->landlordPayable($landlordId);
    }

    /** Held by payout requests still in progress. */
    public function reserved(int $landlordId, ?int $except = null): float
    {
        return (float) Liquidation::withoutGlobalScopes()->where('landlord_id', $landlordId)->open()
            ->when($except, fn ($q) => $q->whereKeyNot($except))->sum('amount');
    }

    public function available(int $landlordId, ?int $except = null): float
    {
        return max(0, round($this->owed($landlordId) - $this->reserved($landlordId, $except), 2));
    }

    /** @return array{amount: float, fee: float, net: float} */
    public function quote(string $method, float $amount): array
    {
        $fee = $this->fees->feeFor($method, $amount);

        return ['amount' => $amount, 'fee' => $fee, 'net' => round($amount - $fee, 2)];
    }

    // --- Landlord ---------------------------------------------------------------

    public function request(Landlord $landlord, PayoutAccount $account, float $amount, User $by, ?string $note = null): Liquidation
    {
        $amount = round($amount);

        $liquidation = DB::transaction(function () use ($landlord, $account, $amount, $by, $note) {
            // One request at a time per landlord, so two can't spend the same balance.
            Landlord::whereKey($landlord->id)->lockForUpdate()->first();

            if ($account->landlord_id !== $landlord->id || !$account->isUsable()) {
                throw new LiquidationException('Choose a verified payout account.');
            }
            if ($amount < self::MIN_AMOUNT) {
                throw new LiquidationException('The smallest payout is UGX '.number_format(self::MIN_AMOUNT).'.');
            }
            $available = $this->available($landlord->id);
            if ($amount > $available) {
                throw new LiquidationException('That is more than your available balance of UGX '.number_format($available).'.');
            }

            $quote = $this->quote($account->type, $amount);
            if ($quote['net'] <= 0) {
                throw new LiquidationException('That amount does not cover the transfer charge of UGX '.number_format($quote['fee']).'.');
            }
            if ($account->type === 'mobile_money' && $quote['net'] > self::MOBILE_MONEY_MAX) {
                throw new LiquidationException('Mobile money payouts are limited to UGX '.number_format(self::MOBILE_MONEY_MAX).' each. Request a smaller amount or use a bank account.');
            }

            return Liquidation::create([
                'reference' => $this->newReference(),
                'landlord_id' => $landlord->id,
                'payout_account_id' => $account->id,
                'method' => $account->type,
                'destination' => $account->snapshot(),
                'amount' => $amount,
                'fee' => $quote['fee'],
                'net_amount' => $quote['net'],
                'status' => Liquidation::PENDING,
                'request_note' => $note ? mb_substr($note, 0, 300) : null,
                'requested_by_user_id' => $by->id,
            ]);
        });

        $this->notifyApprovers($liquidation->load('landlord'));

        return $liquidation;
    }

    public function cancel(Liquidation $liquidation): void
    {
        $this->transition($liquidation, [Liquidation::PENDING], fn (Liquidation $l) => $l->update(['status' => Liquidation::CANCELLED]),
            'Only a request that is still awaiting approval can be cancelled.');
    }

    // --- Staff ------------------------------------------------------------------

    public function approve(Liquidation $liquidation, StaffUser $staff, ?string $note = null): void
    {
        $this->transition($liquidation, [Liquidation::PENDING], function (Liquidation $l) use ($staff, $note) {
            Landlord::whereKey($l->landlord_id)->lockForUpdate()->first();

            if ($l->payout_account_id && !PayoutAccount::withoutGlobalScopes()->whereKey($l->payout_account_id)->where('status', 'verified')->exists()) {
                throw new LiquidationException('The payout account is no longer verified. Reject this request.');
            }
            $available = $this->available($l->landlord_id, $l->id);
            if ((float) $l->amount > $available) {
                throw new LiquidationException('The landlord\'s available balance (UGX '.number_format($available).') no longer covers this payout.');
            }

            $l->update(['status' => Liquidation::APPROVED, 'reviewed_by_staff_user_id' => $staff->id, 'reviewed_at' => now(), 'review_note' => $note]);
        }, 'Only a request awaiting approval can be approved.');
    }

    public function reject(Liquidation $liquidation, StaffUser $staff, string $reason): void
    {
        $this->transition($liquidation, [Liquidation::PENDING, Liquidation::APPROVED, Liquidation::FAILED], function (Liquidation $l) use ($staff, $reason) {
            $l->update([
                'status' => Liquidation::REJECTED,
                'reviewed_by_staff_user_id' => $l->reviewed_by_staff_user_id ?? $staff->id,
                'reviewed_at' => $l->reviewed_at ?? now(),
                'review_note' => $reason,
            ]);
        }, 'This payout can no longer be rejected.');

        $this->notifyLandlord($liquidation->fresh('landlord'), 'rejected');
    }

    /**
     * Pay through Yo! Payments: mobile money, or a bank account registered on
     * the Yo! account (also retries a failed attempt, under a new reference).
     * The executor must not be the approver.
     */
    public function sendViaYo(Liquidation $liquidation, StaffUser $staff, ?string $transferType = null): Liquidation
    {
        $gateway = $this->gateways->gateway();
        if (!$gateway instanceof PayoutGateway || !$gateway->canSendPayouts()) {
            throw new LiquidationException('Payouts through Yo! are not set up (Yo! credentials and signing key).');
        }

        // Commit the attempt before contacting Yo!: a double click or a crash
        // mid-request can then never send the same payout twice.
        $this->transition($liquidation, [Liquidation::APPROVED, Liquidation::FAILED], function (Liquidation $l) use ($staff, $gateway, $transferType) {
            $this->ensureSecondPerson($l, $staff);

            $destination = $l->destination ?? [];
            if (!$l->isMobileMoney()) {
                $account = $l->payout_account_id ? PayoutAccount::withoutGlobalScopes()->find($l->payout_account_id) : null;
                if (!$account || !$account->isUsable() || !$account->yo_bank_identifier) {
                    throw new LiquidationException('This bank account is not registered with Yo! Payments yet. Register it under Payout accounts, or pay by direct transfer.');
                }
                if ($account->account_number !== ($destination['account_number'] ?? null)) {
                    throw new LiquidationException('The payout account changed after this request was made. Reject it and ask the landlord to request again.');
                }
                $destination['yo_bank_identifier'] = $account->yo_bank_identifier;
            }

            $attempt = $l->attempts + 1;
            $l->update([
                'status' => Liquidation::PROCESSING,
                'route' => 'yo',
                'transfer_type' => $l->isMobileMoney() ? null : (in_array($transferType, ['EFT', 'RTGS'], true) ? $transferType : null),
                'destination' => $destination,
                'attempts' => $attempt,
                'external_reference' => $l->reference.'-'.$attempt,
                'gateway' => $gateway->name(),
                'gateway_reference' => null,
                'network_reference' => null,
                'status_message' => null,
                'executed_by_staff_user_id' => $staff->id,
                'executed_at' => now(),
                'sent_at' => now(),
            ]);
        }, 'Only an approved (or failed) payout can be sent.');

        $liquidation = $liquidation->fresh('landlord');

        return $this->apply($liquidation, $gateway->sendPayout($liquidation));
    }

    /** @deprecated use sendViaYo() */
    public function sendMobileMoney(Liquidation $liquidation, StaffUser $staff): Liquidation
    {
        return $this->sendViaYo($liquidation, $staff);
    }

    /**
     * A bank payout made by direct EFT from Primora's bank, outside the system.
     * Also the fallback after a failed Yo! bank attempt (never while one is in flight).
     */
    public function completeBank(Liquidation $liquidation, StaffUser $staff, string $bankReference, string $transferDate): void
    {
        $this->transition($liquidation, [Liquidation::APPROVED, Liquidation::FAILED], function (Liquidation $l) use ($staff, $bankReference, $transferDate) {
            if ($l->isMobileMoney()) {
                throw new LiquidationException('This is a mobile money payout: send it through Yo! instead.');
            }
            $this->ensureSecondPerson($l, $staff);

            $l->update([
                'status' => Liquidation::COMPLETED,
                'route' => 'direct',
                'bank_reference' => $bankReference,
                'transfer_date' => $transferDate,
                'executed_by_staff_user_id' => $staff->id,
                'executed_at' => now(),
                'completed_at' => now(),
            ]);
            BookKeeper::attempt(fn () => $this->postings->liquidationCompleted($l->fresh('landlord')));
        }, 'Only an approved (or failed) payout can be completed by direct transfer.');

        $this->notifyLandlord($liquidation->fresh('landlord'), 'completed');
    }

    /** Ask the gateway how a mobile money payout in progress is doing. */
    public function refresh(Liquidation $liquidation): Liquidation
    {
        if ($liquidation->status !== Liquidation::PROCESSING || !$liquidation->gateway) {
            return $liquidation;
        }

        $gateway = $this->gateways->gateway($liquidation->gateway);
        if (!$gateway instanceof PayoutGateway) {
            return $liquidation;
        }

        return $this->apply($liquidation, $gateway->checkPayout($liquidation));
    }

    // --------------------------------------------------------------------------

    protected function apply(Liquidation $liquidation, GatewayResult $result): Liquidation
    {
        $completed = false;

        DB::transaction(function () use ($liquidation, $result, &$completed) {
            $l = Liquidation::withoutGlobalScopes()->lockForUpdate()->findOrFail($liquidation->id);
            if ($l->status !== Liquidation::PROCESSING) {
                return; // already settled by another check
            }

            $updates = array_filter([
                'gateway_reference' => $result->gatewayReference,
                'network_reference' => $result->networkReference,
            ]) + [
                'status_message' => $result->message ? Str::limit($result->message, 490) : $l->status_message,
                'last_response' => $result->raw ?: $l->last_response,
                'last_checked_at' => now(),
            ];

            $status = $result->status;
            if ($result->notFound && $l->sent_at && $l->sent_at->lt(now()->subMinutes(self::NOT_FOUND_AFTER_MINUTES))) {
                $status = PaymentTransaction::FAILED;
                $updates['status_message'] = 'Yo! Payments has no record of this payout, so no money was sent. It can be retried.';
            }

            if ($status === PaymentTransaction::SUCCEEDED) {
                $updates['status'] = Liquidation::COMPLETED;
                $updates['completed_at'] = now();
                $completed = true;
            } elseif ($status === PaymentTransaction::FAILED) {
                $updates['status'] = Liquidation::FAILED;
            }

            $l->update($updates);

            if ($completed) {
                BookKeeper::attempt(fn () => $this->postings->liquidationCompleted($l->fresh('landlord')));
            }
        });

        $liquidation = $liquidation->fresh('landlord');
        if ($completed) {
            $this->notifyLandlord($liquidation, 'completed');
        }

        return $liquidation;
    }

    /** Lock the row, check its status, then change it. */
    protected function transition(Liquidation $liquidation, array $from, callable $change, string $wrongStatus): void
    {
        DB::transaction(function () use ($liquidation, $from, $change, $wrongStatus) {
            $l = Liquidation::withoutGlobalScopes()->lockForUpdate()->findOrFail($liquidation->id);
            if (!in_array($l->status, $from, true)) {
                throw new LiquidationException($wrongStatus);
            }
            $change($l);
        });
    }

    protected function ensureSecondPerson(Liquidation $l, StaffUser $staff): void
    {
        if ($l->reviewed_by_staff_user_id === $staff->id) {
            throw new LiquidationException('You approved this payout, so a different staff member must execute it.');
        }
    }

    protected function newReference(): string
    {
        do {
            $random = strtr(Str::upper(Str::random(6)), ['O' => 'X', '0' => '7', 'I' => 'K', '1' => '9', 'L' => 'M']);
            $reference = 'LIQ-'.now()->format('ymd').'-'.$random;
        } while (Liquidation::withoutGlobalScopes()->where('reference', $reference)->exists());

        return $reference;
    }

    // --- Emails ---------------------------------------------------------------

    protected function notifyApprovers(Liquidation $l): void
    {
        $emails = StaffUser::where('is_active', true)->get()
            ->filter(fn (StaffUser $s) => $s->hasPermission('liquidations.approve'))->pluck('email')->all();

        $this->send($emails, new NoticeMail(
            subject: "Payout request {$l->reference}: UGX ".number_format((float) $l->amount)." for {$l->landlord->name}",
            heading: 'A landlord has requested a payout',
            lines: [
                "{$l->landlord->name} asked to be paid UGX ".number_format((float) $l->amount).' by '.($l->isMobileMoney() ? 'mobile money' : 'bank transfer').'.',
                'To: '.$l->destinationSummary(),
                'It needs approval by one staff member and execution by another.',
            ],
            actionText: 'Review the request',
            actionUrl: route('staff.liquidations.show', $l),
        ));
    }

    protected function notifyLandlord(Liquidation $l, string $what): void
    {
        $landlord = $l->landlord;
        $emails = User::where('landlord_id', $landlord->id)->where('role', 'admin')->where('is_active', true)->pluck('email')
            ->push($landlord->contact_email)->filter()->unique()->values()->all();

        $mail = $what === 'completed'
            ? new NoticeMail(
                subject: "Payout {$l->reference} sent: UGX ".number_format((float) $l->net_amount),
                heading: 'Your payout has been sent',
                lines: array_values(array_filter([
                    'UGX '.number_format((float) $l->net_amount).' has been sent to '.$l->destinationSummary().'.',
                    (float) $l->fee > 0 ? 'Amount requested: UGX '.number_format((float) $l->amount).' · transfer charge: UGX '.number_format((float) $l->fee).'.' : null,
                    $l->network_reference ? "Mobile money reference: {$l->network_reference}" : null,
                    $l->bank_reference ? "Bank reference: {$l->bank_reference}" : null,
                ])),
                actionText: 'View payouts',
                actionUrl: route('payouts.index'),
            )
            : new NoticeMail(
                subject: "Payout request {$l->reference} was not approved",
                heading: 'Your payout request was not approved',
                lines: ['Your request for UGX '.number_format((float) $l->amount).' was not approved.', 'Reason: '.($l->review_note ?: 'not given'), 'The amount is available in your balance again.'],
                actionText: 'View payouts',
                actionUrl: route('payouts.index'),
            );

        $this->send($emails, $mail);
    }

    protected function send(array $to, NoticeMail $mail): void
    {
        if (!$to) {
            return;
        }
        try {
            Mail::to($to)->send($mail);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
