<?php

namespace App\Exceptions;

use RuntimeException;

class LandlordContextMissingException extends RuntimeException
{
    public function __construct(string $model)
    {
        parent::__construct("No landlord context is set while querying {$model}. This must never happen outside login/console bootstrapping — check CurrentLandlord::set() has run.");
    }
}
