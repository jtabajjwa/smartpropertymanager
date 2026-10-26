<?php

namespace App\Payments;

/**
 * A provider callback, reduced to what's needed to find the payment it is
 * about. The callback's own claims (amount, status) are never trusted on
 * their own: PaymentService re-checks the status with the provider.
 */
final class GatewayNotification
{
    public function __construct(
        /** "success" or "failure" — what the provider says happened. */
        public readonly string $kind,
        /** Our reference (Yo!: external_ref), if the provider echoes it. */
        public readonly ?string $reference,
        /** The provider's own reference, if given. */
        public readonly ?string $gatewayReference,
        /** Whether the provider's signature checked out. */
        public readonly bool $verified,
        public readonly array $payload = [],
        /** The mobile network's reference, if the callback carries one (Yo! IPN: network_ref). */
        public readonly ?string $networkReference = null,
    ) {
    }
}
