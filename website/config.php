<?php
/*
|--------------------------------------------------------------------------
| Primora website settings
|--------------------------------------------------------------------------
| Everything you are likely to change lives in this one file.
| Edit it before uploading to Namecheap.
*/

$config = [

    // Where the Primora app itself lives. "Sign in" and "Start free trial"
    // buttons point here (…/login and …/signup).
    // Local preview uses the dev server; change to your live app URL, e.g.
    // 'https://app.yourdomain.com'
    'app_url' => 'https://app.primoraproptech.com',

    // Public address of this website (used for SEO/social link previews).
    'site_url' => 'https://www.primoraproptech.com',

    // Contact details shown on the page. Leave a value empty ('') to hide it.
    'contact_email' => 'info@primoraproptech.com',
    'contact_phone' => '+256 757 389014',
    'whatsapp'      => '',        // digits only, e.g. '256700000000'
    'address'       => 'NWSC Station Building, Fourth Street, Lugogo, Kololo IV, Kampala Central Division, Kampala, Uganda',

    // Used on the privacy policy page (privacy.php).
    'company_name'  => 'Primora Proptech Limited',
    'privacy_email' => 'info@primoraproptech.com',

    // Contact form delivery.
    // mail_to:   inbox that receives enquiries from the contact form.
    // mail_from: must be an address on YOUR hosted domain (e.g. no-reply@yourdomain.com),
    //            otherwise Namecheap's mail server may reject or spam-folder it.
    'mail_to'   => 'info@primoraproptech.com',
    'mail_from' => 'no-reply@primoraproptech.com',

    // Live pricing: the site reads the plans marked "Show on website" in the
    // staff portal from the app's API. The key is a secret, so it is NOT set
    // here: put it in config.local.php (see config.local.example.php).
    'plans_api' => [
        'url' => 'https://app.primoraproptech.com/api/website/plans',
        'key' => '',
        'cache_minutes' => 15,
    ],

    // Fallback pricing, used only if the app can't be reached and nothing is
    // cached yet. Each tier: [min units, max units (null = no limit), monthly, quarterly, annual]
    'currency' => 'UGX',
    'tiers' => [
        [1,  6,    15000, 45000,  180000],
        [7,  12,   20000, 60000,  240000],
        [13, 20,   25000, 75000,  300000],
        [21, 30,   30000, 90000,  360000],
        [31, 40,   35000, 105000, 420000],
        [41, 50,   40000, 120000, 480000],
        [51, null, 50000, 150000, 600000],
    ],
];

// Machine/server-specific settings and secrets (not in git, not web-readable).
if (is_file(__DIR__.'/config.local.php')) {
    $config = array_replace_recursive($config, require __DIR__.'/config.local.php');
}

return $config;
