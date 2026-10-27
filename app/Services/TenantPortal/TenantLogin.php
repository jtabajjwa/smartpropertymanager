<?php

namespace App\Services\TenantPortal;

use App\Mail\TenantLoginCodeMail;
use App\Models\Landlord;
use App\Models\Tenant;
use App\Models\TenantLoginCode;
use App\Models\Unit;
use App\Payments\Msisdn;
use App\Services\SmsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tenant sign-in: a tenant gives their phone number, email address or unit
 * number, and proves it is them with a one-time code sent to the phone (by
 * SMS) or email address on their tenant record. Codes are free to the
 * landlord (Primora pays for the SMS).
 *
 * Where a code goes is a channel ("sms" | "email") and a destination (a
 * normalised phone number, or a lower-cased email address).
 */
class TenantLogin
{
    public const SMS = 'sms';
    public const EMAIL = 'email';

    public const CODE_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_SECONDS = 60;
    public const MAX_CODES_PER_HOUR = 5;

    public function __construct(protected SmsService $sms)
    {
    }

    /**
     * Where a code can be sent for what the tenant typed, as key => label
     * (key: see self::key; several when a unit number matches several tenants).
     * An email address gets an email; a phone number, SMS; a unit number, SMS
     * to its tenants' phones (or email, for a tenant without a usable phone).
     *
     * @return array<string, string>
     */
    public function destinations(string $input): array
    {
        $input = trim($input);

        if (str_contains($input, '@')) {
            $email = mb_strtolower($input);

            return filter_var($email, FILTER_VALIDATE_EMAIL) && $this->tenantsFor(self::EMAIL, $email)->isNotEmpty()
                ? [self::key(self::EMAIL, $email) => self::label(self::EMAIL, $email)]
                : [];
        }

        if ($msisdn = Msisdn::normalize($input)) {
            return $this->tenantsWithPhone($msisdn)->isNotEmpty() ? [self::key(self::SMS, $msisdn) => self::label(self::SMS, $msisdn)] : [];
        }

        // A unit number: the current tenants of units with that name.
        $units = Unit::withoutGlobalScopes()->with(['estate' => fn ($q) => $q->withoutGlobalScopes()])
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($input)])
            ->whereIn('landlord_id', Landlord::where('status', 'active')->select('id'))
            ->get();

        $destinations = [];
        foreach ($units as $unit) {
            $tenants = Tenant::withoutGlobalScopes()
                ->whereHas('leases', fn ($q) => $q->withoutGlobalScopes()->where('unit_id', $unit->id)->where('status', 'active'))
                ->get();

            foreach ($tenants as $tenant) {
                $where = $unit->estate ? " · {$unit->estate->name}" : '';
                if ($msisdn = Msisdn::normalize($tenant->primary_phone)) {
                    $destinations[self::key(self::SMS, $msisdn)] = self::label(self::SMS, $msisdn).$where;
                } elseif (filter_var($email = mb_strtolower(trim((string) $tenant->email)), FILTER_VALIDATE_EMAIL)) {
                    $destinations[self::key(self::EMAIL, $email)] = self::label(self::EMAIL, $email).$where;
                }
            }
        }

        return $destinations;
    }

    /** Tenant records a code sent to this destination signs in to. */
    public function tenantsFor(string $channel, string $destination): Collection
    {
        return $channel === self::EMAIL ? $this->tenantsWithEmail($destination) : $this->tenantsWithPhone($destination);
    }

    /**
     * Tenant records (any landlord) whose primary or secondary phone is this
     * number, with at least one lease, for an active landlord.
     *
     * @return Collection<int, Tenant>
     */
    public function tenantsWithPhone(string $msisdn): Collection
    {
        $last9 = substr($msisdn, -9);
        $clean = fn (string $column) => "REPLACE(REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '-', ''), '+', ''), '.', '') LIKE ?";

        return Tenant::withoutGlobalScopes()
            ->where(fn ($q) => $q->whereRaw($clean('primary_phone'), ["%{$last9}"])->orWhereRaw($clean('secondary_phone'), ["%{$last9}"]))
            ->whereIn('landlord_id', Landlord::where('status', 'active')->select('id'))
            ->whereHas('leases', fn ($q) => $q->withoutGlobalScopes())
            ->with('landlord')
            ->get()
            ->filter(fn (Tenant $t) => self::matches($t, self::SMS, $msisdn))
            ->values();
    }

    /**
     * Tenant records (any landlord) with this email address, with at least
     * one lease, for an active landlord.
     *
     * @return Collection<int, Tenant>
     */
    public function tenantsWithEmail(string $email): Collection
    {
        return Tenant::withoutGlobalScopes()
            ->whereRaw('LOWER(TRIM(email)) = ?', [mb_strtolower(trim($email))])
            ->whereIn('landlord_id', Landlord::where('status', 'active')->select('id'))
            ->whereHas('leases', fn ($q) => $q->withoutGlobalScopes())
            ->with('landlord')
            ->get()
            ->values();
    }

    /** Is this destination (still) on the tenant's record? */
    public static function matches(Tenant $tenant, string $channel, ?string $destination): bool
    {
        if ($destination === null || $destination === '') {
            return false;
        }

        return $channel === self::EMAIL
            ? mb_strtolower(trim((string) $tenant->email)) === mb_strtolower($destination)
            : in_array($destination, [Msisdn::normalize($tenant->primary_phone), Msisdn::normalize($tenant->secondary_phone)], true);
    }

    /**
     * Send a new code. Returns null when sent, or the reason it wasn't.
     */
    public function sendCode(string $channel, string $destination, ?string $ip = null): ?string
    {
        $recent = TenantLoginCode::where('channel', $channel)->where('destination', $destination)
            ->where('created_at', '>', now()->subHour())->latest('id')->get();

        if ($recent->first() && $recent->first()->created_at->gt(now()->subSeconds(self::RESEND_SECONDS))) {
            return 'A code was sent less than a minute ago. Please wait a moment before asking for another.';
        }
        if ($recent->count() >= self::MAX_CODES_PER_HOUR) {
            return 'Too many codes requested for this '.($channel === self::EMAIL ? 'email address' : 'number').'. Please try again in an hour.';
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        TenantLoginCode::create([
            'channel' => $channel,
            'destination' => $destination,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::CODE_MINUTES),
            'ip' => $ip,
        ]);

        $sent = $channel === self::EMAIL ? $this->sendEmail($destination, $code) : $this->sms->send($destination, "{$code} is your ".config('app.name').' tenant sign-in code. It expires in '.self::CODE_MINUTES." minutes. Don't share it with anyone.");

        if (!$sent) {
            if (app()->environment('local')) {
                // SMS / email isn't set up on this machine: make the code available to the developer.
                Log::info("Tenant portal sign-in code for {$destination}: {$code}");

                return null;
            }

            return $channel === self::EMAIL
                ? "We couldn't send the email right now. Please try again shortly, or sign in with your phone number."
                : "We couldn't send the SMS right now. Please try again shortly.";
        }

        return null;
    }

    protected function sendEmail(string $email, string $code): bool
    {
        $name = $this->tenantsWithEmail($email)->first()?->name ?? 'there';

        try {
            Mail::to($email)->send(new TenantLoginCodeMail($name, $code, self::CODE_MINUTES));

            return true;
        } catch (\Throwable $e) {
            Log::error('Tenant sign-in code email failed: '.$e->getMessage(), ['email' => $email]);

            return false;
        }
    }

    /** True if $code is the latest unexpired, unused code for this destination (and uses it up). */
    public function verify(string $channel, string $destination, string $code): bool
    {
        $login = TenantLoginCode::where('channel', $channel)->where('destination', $destination)->whereNull('used_at')->latest('id')->first();

        if (!$login || $login->expires_at->isPast() || $login->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        if (!Hash::check(preg_replace('/\D/', '', $code), $login->code_hash)) {
            $login->increment('attempts');

            return false;
        }

        $login->update(['used_at' => now()]);

        return true;
    }

    /** "sms:256772123456" / "email:grace@example.com" */
    public static function key(string $channel, string $destination): string
    {
        return "{$channel}:{$destination}";
    }

    /** @return array{0: string, 1: string}|null [channel, destination] */
    public static function parseKey(string $key): ?array
    {
        [$channel, $destination] = array_pad(explode(':', $key, 2), 2, '');

        return in_array($channel, [self::SMS, self::EMAIL], true) && $destination !== '' ? [$channel, $destination] : null;
    }

    /** A masked destination to show on screen. */
    public static function label(string $channel, string $destination): string
    {
        return $channel === self::EMAIL ? self::maskEmail($destination) : self::mask($destination);
    }

    /** "0772 123 456" → "07•• ••• 456" */
    public static function mask(string $msisdn): string
    {
        $local = '0'.substr($msisdn, 3);

        return substr($local, 0, 2).'•• ••• '.substr($local, -3);
    }

    /** "grace.namata@gmail.com" → "gr•••@gmail.com" */
    public static function maskEmail(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($user, 0, min(2, max(1, mb_strlen($user) - 1))).'•••@'.$domain;
    }
}
