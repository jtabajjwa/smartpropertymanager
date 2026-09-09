<?php

namespace App\Services;

class PhoneFormatter
{
    /**
     * Formats a phone number to standard international format (e.g. +256).
     *
     * @param string|null $phone
     * @return string|null
     */
    public static function format(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        // Remove all non-numeric characters except '+'
        $phone = preg_replace('/[^\d+]/', '', $phone);

        // If it starts with 0, replace with +256 (assuming Uganda context)
        if (str_starts_with($phone, '0')) {
            return '+256' . substr($phone, 1);
        }

        // If it starts with 256, prepend +
        if (str_starts_with($phone, '256')) {
            return '+' . $phone;
        }
        
        // If it still doesn't start with +, assume we need to add +256 if it's 9 digits long
        if (!str_starts_with($phone, '+')) {
            if (strlen($phone) === 9) {
                return '+256' . $phone;
            }
            return '+' . $phone;
        }

        return $phone;
    }
}
