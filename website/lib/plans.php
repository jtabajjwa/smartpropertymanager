<?php
/*
 * Pricing for the website, read live from the Primora app.
 *
 * The site's own server calls GET {plans_api.url} with the shared key in the
 * X-Website-Key header (visitors' browsers never see the key). Results are
 * cached in /cache for a few minutes. If the app can't be reached, the last
 * good copy is used, and failing that the fallback tiers in config.php, so
 * the pricing section always renders.
 */

/**
 * @return array{currency: string, plan_name: ?string, tiers: array, source: string}
 *         tiers: list of [min units, max units|null, monthly, quarterly, annual]
 */
function primora_pricing(array $config): array
{
    $fallback = [
        'currency' => $config['currency'],
        'plan_name' => null,
        'tiers' => $config['tiers'],
        'source' => 'fallback',
    ];

    $api = $config['plans_api'] ?? [];
    if (empty($api['url']) || empty($api['key'])) {
        return $fallback; // live pricing not configured
    }

    $ttl = max(60, (int) ($api['cache_minutes'] ?? 15) * 60);
    $cacheFile = __DIR__.'/../cache/plans.json';
    $cached = primora_read_cache($cacheFile);

    if ($cached && time() - $cached['fetched_at'] < $ttl) {
        return $cached['pricing'] + ['source' => 'cache'];
    }

    $pricing = primora_fetch_pricing($api['url'], $api['key']);

    if ($pricing) {
        primora_write_cache($cacheFile, $pricing, time());

        return $pricing + ['source' => 'live'];
    }

    if ($cached) {
        // App unreachable: keep showing the last good prices, and try again in a minute.
        primora_write_cache($cacheFile, $cached['pricing'], time() - $ttl + 60);

        return $cached['pricing'] + ['source' => 'stale-cache'];
    }

    return $fallback;
}

/** Calls the app's plans API; returns normalised pricing, or null on any problem. */
function primora_fetch_pricing(string $url, string $key): ?array
{
    $headers = ['Accept: application/json', 'X-Website-Key: '.$key];
    $body = null;
    $status = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 5,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $body = @file_get_contents($url, false, $context);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
    }

    if ($status !== 200 || !is_string($body) || $body === '') {
        return null;
    }

    return primora_normalise_pricing(json_decode($body, true));
}

/**
 * Picks the plan the calculator opens on (the first tiered plan, else the
 * first plan) and validates every plan strictly; anything unexpected is
 * rejected rather than shown. Other plans in a different currency, or that
 * fail validation, are left out of the comparison.
 *
 * Also returns `plans` (each: name, tiers, features|null) and `catalogue`
 * (every feature a plan can include) for the plan comparison.
 */
function primora_normalise_pricing($data): ?array
{
    $plans = is_array($data) && isset($data['plans']) && is_array($data['plans']) ? $data['plans'] : [];
    if (!$plans) {
        return null;
    }

    $plan = null;
    foreach ($plans as $candidate) {
        if (($candidate['pricing_model'] ?? null) === 'tiered') {
            $plan = $candidate;
            break;
        }
    }
    $plan ??= $plans[0];

    $primary = primora_normalise_plan($plan);
    if (!$primary) {
        return null;
    }

    $all = [];
    foreach ($plans as $candidate) {
        $normalised = $candidate === $plan ? $primary : primora_normalise_plan($candidate);
        if ($normalised && $normalised['currency'] === $primary['currency']) {
            $all[] = $normalised + ['primary' => $candidate === $plan];
        }
    }
    // Cheapest first, so the comparison reads left to right.
    usort($all, fn ($a, $b) => $a['tiers'][0][2] <=> $b['tiers'][0][2]);

    return [
        'currency' => $primary['currency'],
        'plan_name' => $primary['name'],
        'tiers' => $primary['tiers'],
        'plans' => $all,
        'catalogue' => primora_names($data['feature_catalogue'] ?? null),
    ];
}

/** A list of short, plain feature names, or null if it isn't one. */
function primora_names($list): ?array
{
    if (!is_array($list) || count($list) > 30) {
        return null;
    }
    $names = [];
    foreach ($list as $name) {
        if (!is_string($name) || $name === '' || strlen($name) > 160) {
            return null;
        }
        $names[] = $name;
    }

    return $names;
}

/** @return ?array{name: ?string, currency: string, tiers: array, features: ?array} */
function primora_normalise_plan($plan): ?array
{
    if (!is_array($plan)) {
        return null;
    }

    $currency = strtoupper((string) ($plan['currency'] ?? ''));
    if (!preg_match('/^[A-Z]{3}$/', $currency) || empty($plan['tiers']) || !is_array($plan['tiers'])) {
        return null;
    }

    $tiers = [];
    foreach ($plan['tiers'] as $tier) {
        $min = $tier['min_units'] ?? null;
        $max = $tier['max_units'] ?? null;
        $prices = [$tier['monthly'] ?? null, $tier['quarterly'] ?? null, $tier['annual'] ?? null];

        if (!is_numeric($min) || ($max !== null && !is_numeric($max))) {
            return null;
        }
        foreach ($prices as $price) {
            if (!is_numeric($price) || $price < 0) {
                return null;
            }
        }

        $tiers[] = [(int) $min, $max === null ? null : (int) $max, (int) round($prices[0]), (int) round($prices[1]), (int) round($prices[2])];
    }

    usort($tiers, fn ($a, $b) => $a[0] <=> $b[0]);

    return [
        'name' => is_string($plan['name'] ?? null) && $plan['name'] !== '' && strlen($plan['name']) <= 120 ? $plan['name'] : null,
        'currency' => $currency,
        'tiers' => $tiers,
        'features' => primora_names($plan['features'] ?? null),
    ];
}

/**
 * Plans to compare: at least two, each with a name and a feature list, and a
 * catalogue to compare against. Otherwise [] (the page shows one plan).
 */
function primora_comparable_plans(array $pricing): array
{
    $plans = $pricing['plans'] ?? [];
    $catalogue = $pricing['catalogue'] ?? null;
    if (count($plans) < 2 || !$catalogue) {
        return [];
    }
    foreach ($plans as $plan) {
        if ($plan['name'] === null || $plan['features'] === null) {
            return [];
        }
    }

    return $plans;
}

function primora_read_cache(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }

    $data = json_decode((string) @file_get_contents($file), true);

    return isset($data['fetched_at'], $data['pricing']['tiers']) ? $data : null;
}

function primora_write_cache(string $file, array $pricing, int $fetchedAt): void
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return; // caching is optional; carry on without it
    }

    $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
    if (@file_put_contents($tmp, json_encode(['fetched_at' => $fetchedAt, 'pricing' => $pricing])) !== false) {
        @rename($tmp, $file);
    }
}

/** The tier that covers $units (or the last tier). */
function primora_tier_for(array $tiers, int $units): array
{
    foreach ($tiers as $tier) {
        if ($units >= $tier[0] && ($tier[1] === null || $units <= $tier[1])) {
            return $tier;
        }
    }

    return end($tiers);
}
