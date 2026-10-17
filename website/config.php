<?php
/*
|--------------------------------------------------------------------------
| Primora website settings
|--------------------------------------------------------------------------
| Everything you are likely to change lives in this one file.
| Edit it before uploading to Namecheap.
*/

return [

    // Where the Primora app itself lives. "Sign in" and "Start free trial"
    // buttons point here (…/login and …/signup).
    // Local preview uses the dev server; change to your live app URL, e.g.
    // 'https://app.yourdomain.com'
    'app_url' => 'https://app.primoraproptech.com',

    // Public address of this website (used for SEO/social link previews).
    'site_url' => 'https://www.yourdomain.com',

    // Contact details shown on the page. Leave a value empty ('') to hide it.
    'contact_email' => '',
    'contact_phone' => '',        // e.g. '+256 700 000 000'
    'whatsapp'      => '',        // digits only, e.g. '256700000000'
    'address'       => 'Kampala, Uganda',

    // Contact form delivery.
    // mail_to:   inbox that receives enquiries from the contact form.
    // mail_from: must be an address on YOUR hosted domain (e.g. no-reply@yourdomain.com),
    //            otherwise Namecheap's mail server may reject or spam-folder it.
    'mail_to'   => 'info@primoraproptech.com',
    'mail_from' => 'no-repy@primoraproptech.com',

    // Pricing shown on the page — keep in sync with the plans in the staff portal.
    // Each tier: [min units, max units (null = no limit), monthly price, annual price]
    'currency' => 'UGX',
    'tiers' => [
        [1,  6,    15000, 180000],
        [7,  12,   20000, 240000],
        [13, 20,   25000, 300000],
        [21, 30,   30000, 360000],
        [31, 40,   35000, 420000],
        [41, 50,   40000, 480000],
        [51, null, 50000, 600000],
    ],
];
