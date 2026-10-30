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
        // Keys trimmed: menu variables are sometimes configured with stray spaces.
        $payload = collect(json_decode($request->getContent(), true) ?: $request->all())
            ->mapWithKeys(fn ($value, $key) => [trim((string) $key) => is_scalar($value) ? trim((string) $value) : $value])
            ->all();

        if (!$callout->verify($payload)) {
            Log::warning('Yo! USSD call-out with a bad signature', ['step' => $step, 'ip' => $request->ip(), 'msisdn' => $payload['anumbermsisdn'] ?? null]);

            return response()->json($callout->fail('We could not verify this request. Please try again.'));
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
