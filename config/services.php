<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    
    'marz_sms' => [
        'enabled' => env('MARZ_SMS_ENABLED', false),
        'api_key' => env('MARZ_SMS_API_KEY'),
        'api_secret' => env('MARZ_SMS_API_SECRET'),
        'base_url' => env('MARZ_SMS_BASE_URL', 'https://sms.wearemarz.com/api/v1'),
    ],

    // The marketing website (primoraproptech.com) reads published plans from
    // /api/website/plans, authenticating with this shared key. Unset = API off.
    'website' => [
        'api_key' => env('WEBSITE_API_KEY'),
    ],

    // Tenant WhatsApp bot (Meta WhatsApp Cloud API; see deploy/WHATSAPP.md).
    // Without a token and phone number id, replies are only logged.
    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v24.0'),
        // Shown to tenants, e.g. on the portal: "+256 7xx xxx xxx".
        'display_number' => env('WHATSAPP_DISPLAY_NUMBER'),
    ],

    // Yo! Custom USSD call-outs (Yo! hosts the menu; see deploy/YO_USSD.md).
    // Requests are signed with Yo!'s key for this mode ("sandbox" or "production").
    'yo_ussd' => [
        'mode' => env('YO_USSD_MODE', env('YO_MODE', 'sandbox')),
        'public_keys' => [
            'sandbox' => resource_path('certs/yo/ussd-sandbox.pem'),
            'production' => resource_path('certs/yo/ussd-production.pem'),
        ],
        // A reference handed out but never paid against is dropped after this.
        'reference_valid_hours' => 3,
    ],

];
