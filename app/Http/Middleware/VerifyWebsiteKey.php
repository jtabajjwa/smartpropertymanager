<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the marketing-website API with a shared key sent in the
 * X-Website-Key header. Fails closed: if no key is configured, the API is off.
 */
class VerifyWebsiteKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.website.api_key');

        if ($expected === '') {
            return response()->json(['message' => 'Website API is not enabled.'], 503);
        }

        $given = (string) $request->header('X-Website-Key', '');

        // Constant-time comparison, so the key can't be guessed by timing.
        if ($given === '' || !hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
