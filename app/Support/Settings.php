<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Small key/value store for platform settings staff can change, e.g.
 * Settings::int('sms.starter_credits', 50). Values are cached per request.
 */
final class Settings
{
    protected static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        static::$cache ??= AppSetting::pluck('value', 'key')->all();

        return array_key_exists($key, static::$cache) ? static::$cache[$key] : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = static::get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        AppSetting::updateOrCreate(['key' => $key], ['value' => $value === null ? null : (string) $value]);
        static::$cache = null;
    }

    public static function flush(): void
    {
        static::$cache = null;
    }
}
