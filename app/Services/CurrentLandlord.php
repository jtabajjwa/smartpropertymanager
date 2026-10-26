<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;

class CurrentLandlord
{
    protected static ?int $id = null;

    protected static bool $explicit = false;

    public static function set(?int $landlordId): void
    {
        static::$id = $landlordId;
        static::$explicit = true;
    }

    /**
     * Run $callback with $landlordId as the current landlord, then put back
     * whatever was there (for work done outside a landlord request, e.g. a
     * payment confirmed by a gateway callback).
     */
    public static function runAs(int $landlordId, callable $callback): mixed
    {
        [$id, $explicit] = [static::$id, static::$explicit];
        static::set($landlordId);

        try {
            return $callback();
        } finally {
            [static::$id, static::$explicit] = [$id, $explicit];
        }
    }

    public static function clear(): void
    {
        static::$id = null;
        static::$explicit = false;
    }

    /**
     * The landlord whose data the current request may see.
     *
     * Falls back to the signed-in user when nothing was set explicitly. This
     * matters for route-model binding, which resolves before ResolveLandlord
     * runs: a landlord user sees their own landlord, and a staff user in
     * "staff mode" sees the landlord whose portal they opened.
     */
    public static function id(): ?int
    {
        if (static::$explicit) {
            return static::$id;
        }

        if (Auth::guard('web')->check()) {
            return Auth::guard('web')->user()->landlord_id;
        }

        if (Auth::guard('staff')->check()) {
            return StaffActingSession::landlordId();
        }

        return null;
    }
}
