<?php

namespace App\Support;

/**
 * Countries a phone number can be entered for (sign-up's country-code
 * picker), with the national number each expects after the dial code:
 * the number without its leading 0. Uganda first, as the default.
 */
class PhoneCountries
{
    public const DEFAULT = 'UG';

    /** iso => [name, dial code, national number pattern, example] */
    public const ALL = [
        'UG' => ['Uganda', '256', '[347]\d{8}', '772 123456'],
        'KE' => ['Kenya', '254', '[17]\d{8}', '712 345678'],
        'TZ' => ['Tanzania', '255', '[2-7]\d{8}', '712 345678'],
        'RW' => ['Rwanda', '250', '[27]\d{8}', '788 123456'],
        'BI' => ['Burundi', '257', '\d{8}', '79 123456'],
        'SS' => ['South Sudan', '211', '9\d{8}', '912 345678'],
        'CD' => ['DR Congo', '243', '\d{9}', '812 345678'],
        'ET' => ['Ethiopia', '251', '[79]\d{8}', '911 234567'],
        'NG' => ['Nigeria', '234', '[789]\d{9}', '802 123 4567'],
        'GH' => ['Ghana', '233', '[235]\d{8}', '24 123 4567'],
        'ZM' => ['Zambia', '260', '\d{9}', '955 123456'],
        'ZA' => ['South Africa', '27', '\d{9}', '82 123 4567'],
        'AE' => ['United Arab Emirates', '971', '\d{8,9}', '50 123 4567'],
        'IN' => ['India', '91', '[6-9]\d{9}', '98765 43210'],
        'GB' => ['United Kingdom', '44', '\d{9,10}', '7400 123456'],
        'US' => ['United States / Canada', '1', '[2-9]\d{9}', '201 555 0123'],
    ];

    /** For the sign-up page's JavaScript. */
    public static function forJs(): array
    {
        return collect(self::ALL)->map(fn ($c, $iso) => [
            'iso' => $iso, 'name' => $c[0], 'dial' => $c[1], 'pattern' => $c[2], 'example' => $c[3],
        ])->values()->all();
    }

    /** True if $phone is "+<dial code><national number>" for one of the countries. */
    public static function isValid(?string $phone): bool
    {
        foreach (self::ALL as [, $dial, $pattern]) {
            if (preg_match('/^\+'.$dial.'(?:'.$pattern.')$/', (string) $phone)) {
                return true;
            }
        }

        return false;
    }

    /** The country a stored number belongs to (longest dial code wins), for refilling the form. */
    public static function split(?string $phone): array
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        $countries = collect(self::ALL)->sortByDesc(fn ($c) => strlen($c[1]));

        foreach ($countries as $iso => [, $dial]) {
            if ($digits !== '' && str_starts_with((string) $phone, '+') && str_starts_with($digits, $dial)) {
                return [$iso, substr($digits, strlen($dial))];
            }
        }

        return [self::DEFAULT, $digits];
    }
}
