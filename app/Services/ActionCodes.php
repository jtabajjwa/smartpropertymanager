<?php

namespace App\Services;

use App\Mail\ActionCodeMail;
use App\Models\User;
use App\Models\UserActionCode;
use App\Payments\Msisdn;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Second factor for sensitive actions by a landlord user (e.g. adding a
 * payout account): after their password, a one-time code is sent to the
 * user's email address and, when they have one, by SMS to their phone.
 * Primora pays for these SMS (not the landlord's credits).
 */
class ActionCodes
{
    public const PAYOUT_ACCOUNT = 'payout_account';
    public const PAYOUT_REQUEST = 'payout_request';

    public const CODE_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_SECONDS = 60;
    public const MAX_CODES_PER_HOUR = 5;

    public function __construct(protected SmsService $sms)
    {
    }

    /**
     * Send a new code for $purpose; $action describes it to the user ("add payout account MTN 0772…").
     * Returns null when sent, or the reason it wasn't.
     */
    public function send(User $user, string $purpose, string $action, ?string $ip = null): ?string
    {
        $recent = UserActionCode::where('user_id', $user->id)->where('purpose', $purpose)
            ->where('created_at', '>', now()->subHour())->latest('id')->get();

        if ($recent->first() && $recent->first()->created_at->gt(now()->subSeconds(self::RESEND_SECONDS))) {
            return 'A code was sent less than a minute ago. Please wait a moment before asking for another.';
        }
        if ($recent->count() >= self::MAX_CODES_PER_HOUR) {
            return 'Too many codes requested. Please try again in an hour.';
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Only the newest code works.
        UserActionCode::where('user_id', $user->id)->where('purpose', $purpose)->whereNull('used_at')->update(['used_at' => now()]);
        UserActionCode::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::CODE_MINUTES),
            'ip' => $ip,
        ]);

        $sent = false;
        if (filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            try {
                Mail::to($user->email)->send(new ActionCodeMail($user->name, $code, $action, self::CODE_MINUTES));
                $sent = true;
            } catch (\Throwable $e) {
                Log::error('Action code email failed: '.$e->getMessage(), ['user_id' => $user->id, 'purpose' => $purpose]);
            }
        }
        if ($msisdn = Msisdn::normalize((string) $user->phone)) {
            $sent = $this->sms->send($msisdn, "{$code} is your ".config('app.name')." code to {$action}. It expires in ".self::CODE_MINUTES." minutes. Don't share it with anyone.") || $sent;
        }

        if (!$sent) {
            if (app()->environment('local')) {
                // Mail / SMS isn't set up on this machine: make the code available to the developer.
                Log::info("Action code for user {$user->id} ({$purpose}): {$code}");

                return null;
            }

            return "We couldn't send your confirmation code right now. Please try again shortly.";
        }

        return null;
    }

    /** True if $code is the user's latest unexpired, unused code for $purpose (and uses it up). */
    public function verify(User $user, string $purpose, string $code): bool
    {
        $row = UserActionCode::where('user_id', $user->id)->where('purpose', $purpose)->whereNull('used_at')->latest('id')->first();

        if (!$row || $row->expires_at->isPast() || $row->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        if (!Hash::check(preg_replace('/\D/', '', $code), $row->code_hash)) {
            $row->increment('attempts');

            return false;
        }

        $row->update(['used_at' => now()]);

        return true;
    }

    /** Where codes go, masked for the screen: "ja•••@gmail.com and 07•• ••• 456". */
    public static function destinations(User $user): string
    {
        $parts = [];
        if (filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            [$name, $domain] = explode('@', $user->email, 2);
            $parts[] = mb_substr($name, 0, min(2, max(1, mb_strlen($name) - 1))).'•••@'.$domain;
        }
        if ($msisdn = Msisdn::normalize((string) $user->phone)) {
            $local = '0'.substr($msisdn, 3);
            $parts[] = substr($local, 0, 2).'•• ••• '.substr($local, -3);
        }

        return implode(' and ', $parts);
    }
}
