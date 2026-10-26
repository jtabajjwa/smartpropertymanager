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

];
