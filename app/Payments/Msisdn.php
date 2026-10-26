<?php

namespace App\Payments;

/**
 * Ugandan mobile-money numbers in the form Yo! Payments expects:
 * international, no "+", e.g. 256772123456.
 */
class Msisdn
{
    /** "0772 123 456", "+256772123456", "772123456" → "256772123456"; null if not a UG mobile number. */
    public static function normalize(?string $input): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $input);

        if (str_starts_with($digits, '0')) {
            $digits = '256'.substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $digits = '256'.$digits;
        }

        return preg_match('/^2567\d{8}$/', $digits) ? $digits : null;
    }

    /** Best guess at the network, for display only (Yo! works it out itself). */
    public static function network(string $msisdn): ?string
    {
        $prefix = substr($msisdn, 3, 2);

        return match (true) {
            in_array($prefix, ['76', '77', '78', '79'], true) => 'MTN',
            in_array($prefix, ['70', '74', '75'], true) => 'Airtel',
            default => null,
        };
    }
}
