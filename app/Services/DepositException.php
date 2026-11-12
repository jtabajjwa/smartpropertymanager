<?php

namespace App\Services;

/** A security deposit can't cover what was asked of it. */
class DepositException extends \RuntimeException
{
}
