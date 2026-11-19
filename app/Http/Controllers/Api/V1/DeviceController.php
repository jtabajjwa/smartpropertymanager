<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\Request;

/**
 * The app registers the phone's push token after signing in (landlords at
 * /devices, tenants at /tenant/devices). A token belongs to one sign-in: a
 * phone signed in as someone else takes it over, and signing out ends it.
 */
class DeviceController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:500'],
            'platform' => ['nullable', 'string', 'in:android,ios'],
        ]);
        $owner = $request->user();

        DeviceToken::updateOrCreate(['token' => $data['token']], [
            'owner_type' => $owner::class,
            'owner_id' => $owner->getKey(),
            'access_token_id' => $owner->currentAccessToken()?->getKey(),
            'platform' => $data['platform'] ?? null,
            'last_seen_at' => now(),
        ]);

        return response()->json(['message' => 'Notifications are on for this phone.']);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:500']]);
        $owner = $request->user();

        DeviceToken::where('token', $data['token'])->where('owner_type', $owner::class)->where('owner_id', $owner->getKey())->delete();

        return response()->json(['message' => 'Notifications are off for this phone.']);
    }

    /** Signing out of the app: that sign-in's phone stops getting notifications. */
    public static function forgetSignIn(?object $accessToken): void
    {
        if ($accessToken?->getKey()) {
            DeviceToken::where('access_token_id', $accessToken->getKey())->delete();
        }
    }
}
