<?php
/*
 * Settings for the staging copy of the website (primoraproptech.com/staging).
 *
 * Copy this file to .deploy/website-staging.config.local.php (git-ignored) and
 * fill in the key. `scripts/deploy-website.sh push staging` uploads it as the
 * staging copy's config.local.php. It is never uploaded to the live site.
 */

return [
    // Adds a "Staging site" bar, keeps the copy out of search engines and
    // tags contact-form emails with [Staging].
    'staging' => true,

    'app_url' => 'https://staging.primoraproptech.com',
    'site_url' => 'https://www.primoraproptech.com/staging',

    'plans_api' => [
        'url' => 'https://staging.primoraproptech.com/api/website/plans',
        // WEBSITE_API_KEY from the staging app's .env:
        //   sudo grep WEBSITE_API_KEY /var/www/primora/staging/shared/.env
        'key' => 'paste-the-staging-website-api-key-here',
    ],
];
