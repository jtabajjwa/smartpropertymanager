<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A person's name: letters, spaces, apostrophes, hyphens and dots (Nakato O'Brien-Kaaya). */
class PersonName implements ValidationRule
{
    /** Also used by the sign-up form's JavaScript (new RegExp(PATTERN, 'u')). */
    public const PATTERN = "^(?=(?:.*\p{L}){2})[\p{L} .'’-]+$";

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !preg_match('/'.self::PATTERN.'/u', trim($value))) {
            $fail('The :attribute can only use letters, spaces and . \' -');
        }
    }
}
