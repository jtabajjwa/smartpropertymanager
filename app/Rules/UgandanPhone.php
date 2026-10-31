<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Ugandan number after PhoneFormatter: +256 and nine digits, mobile
 * (+2567...) or landline (+2563..., +2564...).
 */
class UgandanPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !preg_match('/^\+256[347]\d{8}$/', $value)) {
            $fail('Enter a Ugandan phone number: +256 followed by 9 digits, e.g. +256 772 123456.');
        }
    }
}
