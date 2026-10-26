<?php

namespace App\Payments;

/** A payment couldn't be started; the message is safe to show the user. */
class PaymentException extends \RuntimeException
{
}
