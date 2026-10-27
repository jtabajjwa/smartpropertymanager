<?php

namespace App\Services;

/** A payout step that isn't allowed right now; the message is safe to show. */
class LiquidationException extends \RuntimeException
{
}
