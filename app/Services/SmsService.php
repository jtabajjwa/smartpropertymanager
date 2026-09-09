<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $apiSecret;
    protected bool $enabled;

    public function __construct()
    {
        $this->enabled = (bool) config('services.marz_sms.enabled');
        $this->apiKey = (string) config('services.marz_sms.api_key');
        $this->apiSecret = (string) config('services.marz_sms.api_secret');
        $this->baseUrl = (string) config('services.marz_sms.base_url');
    }

    public function send(string $phone, string $message): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $phone = $this->formatPhone($phone);

        if (! $phone) {
            Log::warning('SMS: Invalid phone number provided, skipping send.');
            return false;
        }

        try {
            $response = Http::withBasicAuth($this->apiKey, $this->apiSecret)
                ->timeout(15)
                ->post("{$this->baseUrl}/sms/send", [
                    'recipient' => $phone,
                    'message' => mb_substr($message, 0, 320),
                ]);

            if ($response->successful()) {
                Log::info("SMS sent to {$phone}");
                return true;
            }

            Log::error("SMS failed to {$phone}: HTTP {$response->status()}", [
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error("SMS exception to {$phone}: {$e->getMessage()}");
            return false;
        }
    }

    public function balance(): ?float
    {
        if (! $this->enabled) {
            return null;
        }

        try {
            $response = Http::withBasicAuth($this->apiKey, $this->apiSecret)
                ->timeout(10)
                ->get("{$this->baseUrl}/account/balance");

            if ($response->successful()) {
                return (float) $response->json('data.balance', 0);
            }

            return null;
        } catch (\Throwable $e) {
            Log::error("SMS balance check failed: {$e->getMessage()}");
            return null;
        }
    }

    protected function formatPhone(string $phone): ?string
    {
        // Strip all non-digit characters
        $digits = preg_replace('/\D/', '', $phone);

        // Handle Uganda numbers
        if (str_starts_with($digits, '256') && strlen($digits) === 12) {
            return '+' . $digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '+256' . substr($digits, 1);
        }

        if (strlen($digits) === 9 && in_array($digits[0], ['7', '3'])) {
            return '+256' . $digits;
        }

        // Already has + prefix in original
        if (str_starts_with($phone, '+') && strlen($digits) >= 10) {
            return '+' . $digits;
        }

        return null;
    }
}
