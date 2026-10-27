<?php

/*
|--------------------------------------------------------------------------
| Payments
|--------------------------------------------------------------------------
| Collections (subscriptions now; tenant rent later) go through a pluggable
| payment gateway. Each gateway implements App\Payments\Contracts\PaymentGateway.
| To add one, write the class and register it under "gateways".
*/

return [

    // Gateway used for new payments. Each transaction records the gateway it
    // was started on, so switching this later doesn't affect payments in flight.
    'default' => env('PAYMENTS_GATEWAY', 'yo'),

    // Branding at the start of every narrative, so collections in the shared
    // pool account can be told apart by purpose: "PRIMORA:SUB …", later "PRIMORA:RNT …".
    'narrative_prefix' => env('PAYMENTS_NARRATIVE_PREFIX', 'PRIMORA'),

    // What each kind of payment does once it succeeds.
    'purposes' => [
        'subscription' => App\Payments\Purposes\SubscriptionPurpose::class,
        'sms' => App\Payments\Purposes\SmsCreditPurpose::class,
        'rent' => App\Payments\Purposes\RentPurpose::class,
    ],

    // Backup polling for payments whose notification hasn't arrived
    // (php artisan payments:reconcile, scheduled every minute).
    'reconcile' => [
        // Poll every run while a payment is younger than this…
        'fast_window_minutes' => 15,
        // …then at most this often…
        'slow_interval_minutes' => 15,
        // …and stop polling automatically after this (needs staff follow-up).
        'give_up_after_hours' => 72,
        // A deposit Yo! still can't find after this long never reached it.
        'not_found_after_minutes' => 30,
    ],

    'gateways' => [

        // Yo! Payments (Yo! Uganda) — mobile money collections via acdepositfunds.
        'yo' => [
            'driver' => App\Payments\Gateways\YoPaymentsGateway::class,
            'api_username' => env('YO_API_USERNAME'),
            'api_password' => env('YO_API_PASSWORD'),
            // "sandbox" or "production"
            'mode' => env('YO_MODE', 'sandbox'),
            'urls' => [
                'sandbox' => ['https://sandbox.yo.co.ug/services/yopaymentsdev/task.php'],
                'production' => [
                    'https://paymentsapi1.yo.co.ug/ybs/task.php',
                    'https://paymentsapi2.yo.co.ug/ybs/task.php',
                ],
            ],
            // Yo!'s public certificates, used to verify IPN / failure-notification signatures.
            'certificates' => [
                'sandbox' => env('YO_SANDBOX_CERT', resource_path('certs/yo/sandbox.crt')),
                'production' => env('YO_PRODUCTION_CERT', resource_path('certs/yo/production.crt')),
            ],
            // Seconds to wait for Yo! to accept a request (NonBlocking, so it's quick).
            'timeout' => (int) env('YO_TIMEOUT', 45),
            // Primora's private key (PEM) for signing withdrawals (acwithdrawfunds). Its public key
            // must be configured on the Yo! account. Keep the file outside the web root.
            'private_key' => env('YO_PRIVATE_KEY_PATH'),
            'private_key_passphrase' => env('YO_PRIVATE_KEY_PASSPHRASE'),
            // Text added to the mobile-money confirmation SMS the payer receives.
            'provider_reference_text' => env('YO_PROVIDER_REFERENCE_TEXT', 'Primora PropTech'),
        ],

        // Local development only: simulates a successful payment after a few
        // seconds, so the payment screens can be tried without real money.
        'fake' => [
            'driver' => App\Payments\Gateways\FakeGateway::class,
            'succeed_after_seconds' => 8,
        ],

    ],

];
