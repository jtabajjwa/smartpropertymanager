<?php
/*
 * Copy this file to config.local.php on each machine/server and fill it in.
 * config.local.php is git-ignored and blocked from web access by .htaccess.
 * Anything set here overrides config.php.
 */

return [
    'plans_api' => [
        // Must match WEBSITE_API_KEY in the Primora app's .env
        'key' => 'paste-the-website-api-key-here',

        // Only needed if the app isn't at the default address, e.g. for local testing:
        // 'url' => 'http://127.0.0.1:8000/api/website/plans',
    ],
];
