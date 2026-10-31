<?php

namespace App\Rules;

use App\Support\PhoneCountries;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** An international number (after PhoneFormatter) for one of the countries in PhoneCountries. */
class PhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !PhoneCountries::isValid($value)) {
            $fail('Choose your country code, then enter the rest of your phone number, e.g. +256 772 123456.');
        }
    }
}
