<?php

namespace App\Payments;

use App\Payments\Contracts\PaymentGateway;
use App\Payments\Contracts\PaymentPurpose;

/**
 * Resolves gateways and purposes from config/payments.php.
 */
class PaymentGatewayManager
{
    /** @var array<string, PaymentGateway> */
    protected array $gateways = [];

    public function gateway(?string $name = null): PaymentGateway
    {
        $name ??= config('payments.default');

        if (!isset($this->gateways[$name])) {
            $config = config("payments.gateways.{$name}");

            if (!$config || empty($config['driver'])) {
                throw new \InvalidArgumentException("Payment gateway [{$name}] is not configured.");
            }

            $gateway = new $config['driver']($config);

            if (!$gateway instanceof PaymentGateway) {
                throw new \InvalidArgumentException("[{$config['driver']}] is not a payment gateway.");
            }

            $this->gateways[$name] = $gateway;
        }

        return $this->gateways[$name];
    }

    public function purpose(string $purpose): PaymentPurpose
    {
        $class = config("payments.purposes.{$purpose}");

        if (!$class) {
            throw new \InvalidArgumentException("Payment purpose [{$purpose}] is not registered.");
        }

        return app($class);
    }
}
