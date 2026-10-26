<?php

namespace App\Services;

use App\Models\Landlord;
use App\Models\StaffUser;
use Illuminate\Support\Facades\Auth;

/**
 * "Staff mode": a signed-in staff user working inside one landlord's portal
 * on the landlord's behalf. The staff user stays signed in as themselves
 * (never as a landlord user), so audits record staff_user_id, not user_id.
 *
 * The chosen landlord lives in the session; ResolveLandlord turns it into
 * the CurrentLandlord context on every landlord-portal request.
 */
class StaffActingSession
{
    public const SESSION_KEY = 'staff_acting_landlord_id';

    public static function start(Landlord $landlord): void
    {
        session()->put(self::SESSION_KEY, $landlord->id);
    }

    /** Ends staff mode; returns the landlord id that was being acted for. */
    public static function stop(): ?int
    {
        return session()->pull(self::SESSION_KEY);
    }

    public static function landlordId(): ?int
    {
        $id = session(self::SESSION_KEY);

        return $id ? (int) $id : null;
    }

    /** True while the current request is a staff user working in a landlord portal. */
    public static function active(): bool
    {
        return Auth::user() instanceof StaffUser && static::landlordId() !== null;
    }

    public static function landlord(): ?Landlord
    {
        return static::active() ? Landlord::find(static::landlordId()) : null;
    }
}
