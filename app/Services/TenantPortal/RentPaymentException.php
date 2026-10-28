<?php

namespace App\Services\TenantPortal;

/** Why a rent payment couldn't start, in words for the tenant; $field is the form field it's about, if any. */
class RentPaymentException extends \RuntimeException
{
    public function __construct(string $message, public ?string $field = null)
    {
        parent::__construct($message);
    }
}
