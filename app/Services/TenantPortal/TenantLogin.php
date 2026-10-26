<?php

namespace App\Services\TenantPortal;

use App\Models\Landlord;
use App\Models\Tenant;
use App\Models\TenantLoginCode;
use App\Models\Unit;
use App\Payments\Msisdn;
use App\Services\SmsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Tenant sign-in: a tenant gives their phone or unit number, and proves it
 * is them with a one-time code sent by SMS to the phone on their tenant
 * record. Codes are free to the landlord (Primora pays for them).
 */
class TenantLogin
{
    public const CODE_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_SECONDS = 60;
    public const MAX_CODES_PER_HOUR = 5;

    public function __construct(protected SmsService $sms)
    {
    }

    /**
     * Phones a code can be sent to for what the tenant typed, as
     * msisdn => description (for picking one when a unit number matches
     * several tenants).
     *
     * @return array<string, string>
     */
    public function destinations(string $input): array
    {
        $input = trim($input);

        if ($msisdn = Msisdn::normalize($input)) {
            return $this->tenantsWithPhone($msisdn)->isNotEmpty() ? [$msisdn => self::mask($msisdn)] : [];
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
                if ($msisdn = Msisdn::normalize($tenant->primary_phone)) {
                    $destinations[$msisdn] = self::mask($msisdn).($unit->estate ? " · {$unit->estate->name}" : '');
                }
            }
        }

        return $destinations;
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
            ->filter(fn (Tenant $t) => in_array($msisdn, [Msisdn::normalize($t->primary_phone), Msisdn::normalize($t->secondary_phone)], true))
            ->values();
    }

    /**
     * Send a new code to $msisdn. Returns null when sent, or the reason it wasn't.
     */
    public function sendCode(string $msisdn, ?string $ip = null): ?string
    {
        $recent = TenantLoginCode::where('msisdn', $msisdn)->where('created_at', '>', now()->subHour())->latest('id')->get();

        if ($recent->first() && $recent->first()->created_at->gt(now()->subSeconds(self::RESEND_SECONDS))) {
            return 'A code was sent less than a minute ago. Please wait a moment before asking for another.';
        }
        if ($recent->count() >= self::MAX_CODES_PER_HOUR) {
            return 'Too many codes requested for this number. Please try again in an hour.';
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        TenantLoginCode::create([
            'msisdn' => $msisdn,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::CODE_MINUTES),
            'ip' => $ip,
        ]);

        $sent = $this->sms->send($msisdn, "{$code} is your ".config('app.name').' tenant sign-in code. It expires in '.self::CODE_MINUTES." minutes. Don't share it with anyone.");

        if (!$sent) {
            if (app()->environment('local')) {
                // SMS isn't set up on this machine: make the code available to the developer.
                Log::info("Tenant portal sign-in code for {$msisdn}: {$code}");

                return null;
            }

            return "We couldn't send the SMS right now. Please try again shortly.";
        }

        return null;
    }

    /** True if $code is the latest unexpired, unused code for $msisdn (and uses it up). */
    public function verify(string $msisdn, string $code): bool
    {
        $login = TenantLoginCode::where('msisdn', $msisdn)->whereNull('used_at')->latest('id')->first();

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

    /** "0772 123 456" → "07•• ••• 456" */
    public static function mask(string $msisdn): string
    {
        $local = '0'.substr($msisdn, 3);

        return substr($local, 0, 2).'•• ••• '.substr($local, -3);
    }
}
