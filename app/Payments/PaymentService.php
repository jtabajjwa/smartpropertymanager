<?php

namespace App\Payments;

use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Starts payments and moves them through their statuses, the same way for
 * every gateway and every purpose.
 *
 * Status safety rules:
 *  - A succeeded payment never changes again.
 *  - A failed payment only changes if the provider later reports SUCCEEDED
 *    (money was taken, so it must be credited).
 *  - The purpose's effect (e.g. extending a subscription) runs exactly once,
 *    under a row lock, whether the news came from a callback or from polling.
 *  - Callbacks are never trusted on their own: they trigger a fresh status
 *    check with the provider, and only that result is applied.
 */
class PaymentService
{
    public function __construct(protected PaymentGatewayManager $manager)
    {
    }

    public function gatewayAvailable(?string $gateway = null): bool
    {
        try {
            return $this->manager->gateway($gateway)->isConfigured();
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Start collecting a payment. Returns the transaction (usually pending:
     * the payer still has to approve the prompt on their phone).
     *
     * @throws PaymentException when the payment can't be started at all
     */
    public function initiate(
        string $purpose,
        Landlord $landlord,
        float $amount,
        string $msisdn,
        array $context = [],
        ?Model $payable = null,
        ?Authenticatable $initiator = null,
    ): PaymentTransaction {
        $gateway = $this->manager->gateway();
        $handler = $this->manager->purpose($purpose);

        if (!$gateway->isConfigured()) {
            throw new PaymentException('Mobile money payments are not available right now. Please try again later.');
        }

        if ($amount <= 0) {
            throw new PaymentException('The amount to pay must be more than zero.');
        }

        $transaction = PaymentTransaction::create([
            'reference' => $this->makeReference($handler->code()),
            'purpose' => $purpose,
            'landlord_id' => $landlord->id,
            'payable_type' => $payable?->getMorphClass(),
            'payable_id' => $payable?->getKey(),
            'gateway' => $gateway->name(),
            'amount' => $amount,
            'currency' => 'UGX',
            'msisdn' => $msisdn,
            'narrative' => '',
            'status' => PaymentTransaction::PENDING,
            'context' => $context,
            'initiated_by_user_id' => $initiator instanceof User ? $initiator->id : null,
            'initiated_by_staff_user_id' => $initiator instanceof StaffUser ? $initiator->id : null,
        ]);

        $transaction->update(['narrative' => $this->narrative($transaction, $handler)]);

        $result = $gateway->requestPayment($transaction);

        if ($result->status === PaymentTransaction::FAILED && !$result->gatewayReference) {
            // Rejected before anything happened (bad number, network not supported…).
            $this->apply($transaction, $result);

            throw new PaymentException($this->friendlyFailure($result->message));
        }

        $this->apply($transaction, $result);

        return $transaction->fresh();
    }

    /**
     * A payment someone else asked the gateway for (Yo!'s USSD app sends the
     * prompt itself, with our reference): recorded without sending anything,
     * then moved on by the gateway's notification or a status check.
     */
    public function expected(
        string $purpose,
        Landlord $landlord,
        float $amount,
        string $msisdn,
        string $reference,
        string $gateway,
        array $context = [],
        ?Model $payable = null,
    ): PaymentTransaction {
        $handler = $this->manager->purpose($purpose);

        $transaction = PaymentTransaction::create([
            'reference' => $reference,
            'purpose' => $purpose,
            'landlord_id' => $landlord->id,
            'payable_type' => $payable?->getMorphClass(),
            'payable_id' => $payable?->getKey(),
            'gateway' => $gateway,
            'amount' => $amount,
            'currency' => 'UGX',
            'msisdn' => $msisdn,
            'narrative' => '',
            'status' => PaymentTransaction::PENDING,
            'context' => $context,
        ]);

        $transaction->update(['narrative' => $this->narrative($transaction, $handler)]);

        return $transaction;
    }

    /** A new reference for a payment of this purpose ("RNT-261030-K3QX7M"). */
    public function newReference(string $purpose): string
    {
        return $this->makeReference($this->manager->purpose($purpose)->code());
    }

    /** How offline payments can be received (recorded by staff, no gateway involved). */
    public const OFFLINE_METHODS = [
        'cash' => 'Cash',
        'bank_transfer' => 'Bank transfer',
        'mobile_money' => 'Mobile money (sent directly)',
        'other' => 'Other',
    ];

    /**
     * Record money received outside any gateway (cash, bank transfer, …).
     * Goes through the same success path as a gateway payment, so the
     * purpose's effect (e.g. extending a subscription) is identical.
     */
    public function recordOffline(
        string $purpose,
        Landlord $landlord,
        float $amount,
        string $method,
        \DateTimeInterface $receivedAt,
        array $context = [],
        ?string $reference = null,
        ?string $payerPhone = null,
        ?string $note = null,
        ?Model $payable = null,
        ?Authenticatable $recordedBy = null,
    ): PaymentTransaction {
        $handler = $this->manager->purpose($purpose);

        if ($amount <= 0) {
            throw new PaymentException('The amount received must be more than zero.');
        }

        $transaction = PaymentTransaction::create([
            'reference' => $this->makeReference($handler->code()),
            'purpose' => $purpose,
            'landlord_id' => $landlord->id,
            'payable_type' => $payable?->getMorphClass(),
            'payable_id' => $payable?->getKey(),
            'gateway' => 'manual',
            'amount' => $amount,
            'currency' => 'UGX',
            'msisdn' => Msisdn::normalize($payerPhone) ?? '',
            'narrative' => '',
            'status' => PaymentTransaction::PENDING,
            'context' => $context + array_filter([
                'method' => $method,
                'received_on' => \Carbon\Carbon::instance($receivedAt)->toDateString(),
                'offline_reference' => $reference,
                'note' => $note,
            ]),
            'initiated_by_user_id' => $recordedBy instanceof User ? $recordedBy->id : null,
            'initiated_by_staff_user_id' => $recordedBy instanceof StaffUser ? $recordedBy->id : null,
        ]);

        $methodLabel = self::OFFLINE_METHODS[$method] ?? $method;
        $transaction->update(['narrative' => Str::limit($this->narrative($transaction, $handler)." | Offline: {$methodLabel}".($reference ? " ref {$reference}" : ''), 495, '')]);

        $this->apply($transaction, new GatewayResult(
            PaymentTransaction::SUCCEEDED,
            receiptNumber: $reference,
            message: "Recorded offline ({$methodLabel})".($recordedBy ? ' by '.$recordedBy->name : '').'.',
        ));

        $transaction->update(['completed_at' => $receivedAt]);

        return $transaction->fresh();
    }

    /**
     * Ask the gateway for the latest status of an open payment and apply it.
     */
    public function refresh(PaymentTransaction $transaction): PaymentTransaction
    {
        if (!$transaction->isOpen()) {
            return $transaction;
        }

        $result = $this->manager->gateway($transaction->gateway)->checkStatus($transaction);

        // The provider has no record of it. Give it a little time (callbacks
        // and slow networks), then treat it as never having reached them.
        if ($result->notFound) {
            $limit = (int) config('payments.reconcile.not_found_after_minutes', 30);
            $result = $transaction->created_at->lt(now()->subMinutes($limit))
                ? GatewayResult::failed('The payment request never reached the mobile money provider.', $result->raw)
                : GatewayResult::pending($result->message, $result->raw);
        }

        $this->apply($transaction, $result);

        return $transaction->fresh();
    }

    /**
     * Handle a gateway callback (IPN / failure notification). Always safe to
     * call repeatedly; the caller must answer the provider with HTTP 200.
     */
    public function handleNotification(string $gatewayName, string $type, Request $request): GatewayNotification
    {
        $gateway = $this->manager->gateway($gatewayName);
        $notification = $gateway->parseNotification($request, $type);

        $transaction = PaymentTransaction::query()
            ->where('gateway', $gateway->name())
            ->where(function ($q) use ($notification) {
                if ($notification->reference) {
                    $q->orWhere('reference', $notification->reference);
                }
                if ($notification->gatewayReference) {
                    $q->orWhere('gateway_reference', $notification->gatewayReference);
                }
            })
            ->first();

        // A rent payment Yo!'s USSD app started with a reference we handed out.
        if (!$transaction && $gateway->name() === 'yo' && $notification->reference) {
            $reported = $notification->payload['amount'] ?? null;
            $transaction = app(\App\Ussd\UssdPayments::class)->claim($notification->reference, is_numeric($reported) ? (float) $reported : null);
        }

        Log::info('Payment notification received', [
            'gateway' => $gatewayName,
            'type' => $type,
            'verified' => $notification->verified,
            'reference' => $notification->reference,
            'matched' => $transaction?->reference,
        ]);

        app(GatewayLogger::class)->record(
            transaction: $transaction,
            gateway: $gateway->name(),
            action: $type,
            direction: \App\Models\PaymentGatewayLog::INBOUND,
            url: $request->fullUrl(),
            requestBody: $request->getContent() ?: http_build_query($request->post()),
            responseStatus: 200,
            responseBody: $gateway->notificationResponse($notification),
            note: implode(' · ', array_filter([
                $notification->kind === 'failure' ? 'Failure notification' : 'Payment notification',
                $notification->verified ? 'signature verified' : 'signature NOT verified',
                $transaction ? null : 'no matching payment',
                'from '.$request->ip(),
            ])),
        );

        if (!$notification->reference && !$notification->gatewayReference) {
            return $notification;
        }

        if (!$transaction) {
            // E.g. money sent straight to the account, not through this app.
            Log::warning('Payment notification for an unknown transaction', ['payload' => $notification->payload]);

            return $notification;
        }

        $transaction->update(array_filter([
            'notified_at' => now(),
            // Keep the network's reference from a genuine IPN if the status check didn't give one.
            'network_reference' => $notification->verified && $notification->networkReference && !$transaction->network_reference
                ? $notification->networkReference
                : null,
        ]));

        // Don't act on the callback's word: confirm with the provider.
        if ($transaction->status !== PaymentTransaction::SUCCEEDED) {
            $this->apply($transaction, $this->manager->gateway($transaction->gateway)->checkStatus($transaction));
        }

        return $notification;
    }

    /**
     * Record a gateway result on the transaction and, on first success,
     * apply the purpose's effect — all under a row lock.
     */
    public function apply(PaymentTransaction $transaction, GatewayResult $result): void
    {
        DB::transaction(function () use ($transaction, $result) {
            /** @var PaymentTransaction $locked */
            $locked = PaymentTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $handler = $this->manager->purpose($locked->purpose);

            $from = $locked->status;
            $to = $result->status;

            $updates = array_filter([
                'gateway_reference' => $result->gatewayReference,
                'network_reference' => $result->networkReference,
                'receipt_number' => $result->receiptNumber,
            ]) + [
                'last_checked_at' => now(),
                'last_response' => $result->raw ?: $locked->last_response,
            ];

            if ($from === PaymentTransaction::SUCCEEDED) {
                $locked->update($updates); // already final; just keep references fresh

                return;
            }

            if ($from === PaymentTransaction::FAILED && $to !== PaymentTransaction::SUCCEEDED) {
                $locked->update($updates);

                return;
            }

            if ($from === PaymentTransaction::FAILED) {
                Log::warning('Payment previously marked failed was reported succeeded; crediting it.', ['reference' => $locked->reference]);
            }

            // Paid by USSD with the amount typed after our call-out: take what was actually paid.
            if ($to === PaymentTransaction::SUCCEEDED && (float) $locked->amount <= 0 && $result->amount) {
                $updates['amount'] = $result->amount;
            }

            $updates['status'] = $to;
            $updates['status_message'] = $result->message ? Str::limit($result->message, 490) : $locked->status_message;

            if (in_array($to, [PaymentTransaction::SUCCEEDED, PaymentTransaction::FAILED], true)) {
                $updates['completed_at'] = now();
            }

            if ($to === PaymentTransaction::SUCCEEDED && $result->amount !== null && (float) $locked->amount > 0 && abs($result->amount - (float) $locked->amount) > 0.009) {
                Log::warning('Payment amount differs from what was requested', [
                    'reference' => $locked->reference, 'requested' => $locked->amount, 'reported' => $result->amount,
                ]);
            }

            $locked->update($updates);

            if ($to === PaymentTransaction::SUCCEEDED && !$locked->applied_at) {
                $handler->onSucceeded($locked);
                $locked->update(['applied_at' => now()]);
                \App\Accounting\BookKeeper::attempt(fn () => app(\App\Accounting\Postings::class)->paymentSucceeded($locked->fresh(['landlord'])));
            } elseif ($to === PaymentTransaction::FAILED && $from !== PaymentTransaction::FAILED) {
                $handler->onFailed($locked);
            }
        });

        $transaction->refresh();
    }

    // ------------------------------------------------------------------

    /**
     * "SUB-261027-K3QX7M": purpose code, date, and a random part. Sent to the
     * gateway as ExternalReference, so it is how each payment is found again.
     */
    protected function makeReference(string $code): string
    {
        do {
            // Random part only: letters/digits that can't be misread (no 0/O, 1/I/L).
            $random = strtr(Str::upper(Str::random(6)), ['O' => 'X', '0' => '7', 'I' => 'K', '1' => '9', 'L' => 'M']);
            $reference = strtoupper($code).'-'.now()->format('ymd').'-'.$random;
        } while (PaymentTransaction::where('reference', $reference)->exists() || \App\Models\UssdPaymentIntent::where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * Fixed, machine-readable start, then readable details — so collections in
     * the shared pool account can be filtered by purpose and landlord:
     *   "PRIMORA:SUB L7 SUB-261027-K3QX7M | Premium Plan, 7–12 units, Monthly | Kololo Homes Ltd"
     */
    protected function narrative(PaymentTransaction $transaction, \App\Payments\Contracts\PaymentPurpose $handler): string
    {
        $prefix = config('payments.narrative_prefix', 'PRIMORA');

        return Str::limit(
            "{$prefix}:{$handler->code()} L{$transaction->landlord_id} {$transaction->reference} | ".$handler->describe($transaction),
            495,
            ''
        );
    }

    protected function friendlyFailure(?string $message): string
    {
        return 'The payment could not be started'.($message ? ': '.rtrim($message, '.').'.' : '.').' Check the number and try again.';
    }
}
