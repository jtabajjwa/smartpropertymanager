<?php

namespace App\Services;

/** A subscription payment or code that can't go ahead, with a message for the landlord (see SubscriptionCheckout). */
class CheckoutException extends \RuntimeException
{
}
