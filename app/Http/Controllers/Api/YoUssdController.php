<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Ussd\YoUssdCallout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Call-outs from Yo!'s Custom USSD app (see App\Ussd\YoUssdCallout and
 * deploy/YO_USSD.md). One URL per step of the menu:
 *
 *   POST /api/ussd/yo/unit      a unit ID was typed
 *   POST /api/ussd/yo/units     units for the caller's phone, or another tenant's
 *   POST /api/ussd/yo/confirm   the unit picked from that list, and the amount
 *
 * Always answers 200 with the JSON Yo! expects; "validated": false ends the
 * session with the message shown to the caller.
 */
class YoUssdController extends Controller
{
    public function __invoke(Request $request, YoUssdCallout $callout, string $step)
    {
        // Sandbox only: the exact body Yo! sent (same bytes as php://input), to see what each menu step sends.
        if (config('services.yo_ussd.mode') !== 'production') {
            Log::info('Yo! USSD call-out received', ['step' => $step, 'content_type' => $request->header('Content-Type'), 'raw_body' => $request->getContent()]);
        }

        // Keys trimmed: menu variables are sometimes configured with stray spaces.
        $sent = collect(json_decode($request->getContent(), true) ?: $request->all())
            ->mapWithKeys(fn ($value, $key) => [trim((string) $key) => $value]);
        $payload = $sent->map(fn ($value) => is_scalar($value) ? trim((string) $value) : $value)->all();

        // The signature covers the values exactly as sent.
        if (!$callout->verify($sent->all())) {
            Log::warning('Yo! USSD call-out with a bad signature', ['step' => $step, 'mode' => config('services.yo_ussd.mode'), 'content_type' => $request->header('Content-Type'), 'ip' => $request->ip(),
                'anumbermsisdn' => $payload['anumbermsisdn'] ?? null, 'msisdn' => $payload['msisdn'] ?? null, 'code' => $payload['code'] ?? null,
                'fields' => array_keys($payload)] + $callout->failure);

            return response()->json($callout->fail('We could not verify this request. Please try again.'));
        }

        // "Pay for Self" configured against the unit-ID URL: no unit ID was typed, so list the caller's units.
        if ($step === 'unit' && !array_key_exists('property_unit_number', $payload)) {
            Log::info('Yo! USSD unit call-out without a unit ID, answered as units', ['fields' => array_keys($payload)]);
            $step = 'units';
        }

        try {
            $response = match ($step) {
                'unit' => $callout->unit($payload),
                'units' => $callout->units($payload),
                'confirm' => $callout->confirm($payload),
            };
        } catch (\Throwable $e) {
            report($e);
            $response = $callout->fail('Something went wrong on our side. Please try again in a few minutes.');
        }

        return response()->json($response);
    }
}
