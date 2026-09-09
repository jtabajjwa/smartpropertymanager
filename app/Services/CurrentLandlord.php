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

    public static function clear(): void
    {
        static::$id = null;
        static::$explicit = false;
    }

    public static function id(): ?int
    {
        if (static::$explicit) {
            return static::$id;
        }

        return Auth::guard('web')->check() ? Auth::guard('web')->user()->landlord_id : null;
    }
}
