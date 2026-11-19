<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging (HTTP v1 API), signed in with a Google service
 * account: config services.fcm.project_id and services.fcm.credentials (the
 * path to the service account's JSON key file, kept on the server only).
 */
class FcmClient
{
    public const SENT = 'sent';
    public const GONE = 'gone';     // the app was uninstalled / the token is no longer valid: forget it
    public const FAILED = 'failed'; // try again another time

    public function configured(): bool
    {
        $path = config('services.fcm.credentials');

        return config('services.fcm.project_id') && $path && is_readable($path);
    }

    /**
     * @param  array<string, string>  $data  passed to the app (e.g. which screen to open)
     */
    public function send(string $token, string $title, string $body, array $data = []): string
    {
        $response = Http::withToken($this->accessToken())->timeout(10)->post(
            'https://fcm.googleapis.com/v1/projects/'.config('services.fcm.project_id').'/messages:send',
            ['message' => [
                'token' => $token,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => array_map('strval', $data),
                'android' => ['priority' => 'high', 'notification' => ['sound' => 'default']],
                'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
            ]],
        );

        if ($response->successful()) {
            return self::SENT;
        }

        $error = (string) $response->json('error.status');
        $details = collect($response->json('error.details', []))->pluck('errorCode')->filter()->all();
        if ($response->status() === 404 || in_array('UNREGISTERED', $details, true) || ($error === 'INVALID_ARGUMENT' && str_contains((string) $response->json('error.message'), 'token'))) {
            return self::GONE;
        }

        Log::warning('Push notification not sent', ['status' => $response->status(), 'error' => $response->json('error.message')]);

        return self::FAILED;
    }

    /** An OAuth access token for the service account (cached for most of its hour). */
    protected function accessToken(): string
    {
        return Cache::remember('fcm.access_token', now()->addMinutes(50), function () {
            $key = json_decode((string) file_get_contents(config('services.fcm.credentials')), true);
            $now = time();
            $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

            $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode([
                'iss' => $key['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $key['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]);
            openssl_sign($unsigned, $signature, $key['private_key'], OPENSSL_ALGO_SHA256);
            $jwt = $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

            return Http::asForm()->timeout(10)->post($key['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ])->throw()->json('access_token');
        });
    }
}
