<?php

/*
 * The Terms of Service and Privacy Policy landlords agree to (pages on the
 * Primora website). Change the version whenever the terms change in a way
 * landlords must accept: each landlord's admin is then asked to accept the
 * new terms at their next sign-in (EnsureTermsAccepted). Keep it in step
 * with the "Last updated" date on website/terms.php.
 */
return [
    'terms_version' => env('LEGAL_TERMS_VERSION', '2026-11-02'),
    'terms_url' => env('LEGAL_TERMS_URL', 'https://www.primoraproptech.com/terms.php'),
    'privacy_url' => env('LEGAL_PRIVACY_URL', 'https://www.primoraproptech.com/privacy.php'),
];
