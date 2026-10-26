<?php

namespace App\Payments;

use App\Models\PaymentTransaction;

/**
 * A gateway's answer about one payment, in this app's terms.
 */
final class GatewayResult
{
    public function __construct(
        /** One of the PaymentTransaction status constants. */
        public readonly string $status,
        public readonly ?string $gatewayReference = null,
        public readonly ?string $networkReference = null,
        public readonly ?string $receiptNumber = null,
        public readonly ?string $message = null,
        /** Amount the provider reports, if any (for cross-checking). */
        public readonly ?float $amount = null,
        /** Raw provider response, kept on the transaction for support. */
        public readonly array $raw = [],
        /**
         * True when the provider definitely has no record of the payment
         * (e.g. it never arrived); the service decides when that means failed.
         */
        public readonly bool $notFound = false,
    ) {
        if (!in_array($status, [PaymentTransaction::PENDING, PaymentTransaction::SUCCEEDED, PaymentTransaction::FAILED, PaymentTransaction::INDETERMINATE], true)) {
            throw new \InvalidArgumentException("Unknown payment status [{$status}].");
        }
    }

    public static function pending(?string $message = null, array $raw = [], ?string $gatewayReference = null): self
    {
        return new self(PaymentTransaction::PENDING, $gatewayReference, message: $message, raw: $raw);
    }

    public static function failed(string $message, array $raw = [], ?string $gatewayReference = null): self
    {
        return new self(PaymentTransaction::FAILED, $gatewayReference, message: $message, raw: $raw);
    }
}
