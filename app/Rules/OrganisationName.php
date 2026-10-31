<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A company or landlord name: letters and numbers, with the spaces and
 * punctuation business names use (Kampala Properties Ltd., A & B Homes),
 * and at least one letter, so "12345" or "!!!" isn't a name.
 */
class OrganisationName implements ValidationRule
{
    /** Also used by the sign-up form's JavaScript (new RegExp(PATTERN, 'u')). */
    public const PATTERN = "^(?=.*\p{L})[\p{L}\p{N} &.,'’()\/-]+$";

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !preg_match('/'.self::PATTERN.'/u', trim($value))) {
            $fail("The :attribute must contain letters, and can only use letters, numbers, spaces and & . , ' ( ) / -");
        }
    }
}
