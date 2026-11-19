<?php

namespace App\Services;

use App\Mail\NoticeMail;
use App\Models\Landlord;
use App\Models\Liquidation;
use App\Models\PayoutAccount;
use App\Models\StaffUser;
use App\Models\User;
use App\Payments\Msisdn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * The two-step (code-confirmed) payout flows, shared by the web portal and
 * the mobile app's API: adding a payout account, and requesting a payout.
 *
 * Step 1 checks everything and sends a code to the user's email and phone,
 * returning a "pending" array that the caller keeps (web: the session;
 * API: the cache, per user). Step 2 checks the code and does it.
 *
 * Business problems throw LiquidationException (shown as a general error);
 * field problems throw ValidationException (shown under the field).
 */
class PayoutSteps
{
    public function __construct(protected Liquidations $liquidations, protected ActionCodes $codes)
    {
    }

    // --- Payout requests ------------------------------------------------------------

    /** Check the request and send the code. @return array the pending request */
    public function startPayout(User $user, Landlord $landlord, int $accountId, float $amount, ?string $note, ?string $ip): array
    {
        $account = PayoutAccount::find($accountId);
        if (!$account) {
            throw new LiquidationException('Choose one of your payout accounts.');
        }

        // Check it now, so a code is only sent for a request that can go through.
        $quote = $this->liquidations->check($landlord, $account, $amount);

        $amount = round($amount);
        if ($error = $this->codes->send($user, ActionCodes::PAYOUT_REQUEST, 'request a payout of UGX '.number_format($amount).' to '.$account->summary(), $ip)) {
            throw new LiquidationException($error);
        }

        return [
            'landlord_id' => $landlord->id,
            'user_id' => $user->id,
            'payout_account_id' => $account->id,
            'amount' => $amount,
            'fee' => $quote['fee'],
            'net' => $quote['net'],
            'note' => $note,
            'summary' => $account->summary(),
            'expires_at' => now()->addMinutes(ActionCodes::CODE_MINUTES)->timestamp,
        ];
    }

    /** @return array the pending request with a new expiry */
    public function resendPayoutCode(User $user, array $pending, ?string $ip): array
    {
        if ($error = $this->codes->send($user, ActionCodes::PAYOUT_REQUEST, 'request a payout of UGX '.number_format($pending['amount']).' to '.$pending['summary'], $ip)) {
            throw new LiquidationException($error);
        }

        return ['expires_at' => now()->addMinutes(ActionCodes::CODE_MINUTES)->timestamp] + $pending;
    }

    /** The code proves it's really the account holder; then the request goes to Primora. */
    public function confirmPayout(User $user, Landlord $landlord, array $pending, string $code): Liquidation
    {
        $this->verify($user, ActionCodes::PAYOUT_REQUEST, $code);

        $account = PayoutAccount::find($pending['payout_account_id']);
        if (!$account) {
            throw new LiquidationException('Choose a verified payout account.');
        }

        return $this->liquidations->request($landlord, $account, (float) $pending['amount'], $user, $pending['note'] ?? null);
    }

    // --- Payout accounts ------------------------------------------------------------

    /**
     * Check a new account and send the code.
     *
     * @param  array{type: string, account_name: string, msisdn?: ?string, bank_name?: ?string, bank_branch?: ?string, account_number?: ?string, swift_code?: ?string}  $data
     * @return array the pending account
     */
    public function startAccount(User $user, Landlord $landlord, array $data, ?string $ip): array
    {
        $fields = ['type' => $data['type'], 'account_name' => trim($data['account_name'])];
        if ($data['type'] === 'mobile_money') {
            $msisdn = Msisdn::normalize((string) ($data['msisdn'] ?? ''));
            if (!$msisdn) {
                throw ValidationException::withMessages(['msisdn' => 'Enter a Ugandan MTN or Airtel number, e.g. 0772 123 456.']);
            }
            $fields['msisdn'] = $msisdn;
            if (PayoutAccount::mobileMoneyInUse() >= PayoutAccount::mobileMoneyLimit()) {
                throw ValidationException::withMessages(['msisdn' => $this->limitMessage()]);
            }
        } else {
            $fields += [
                'bank_name' => trim((string) $data['bank_name']),
                'bank_branch' => !empty($data['bank_branch']) ? trim($data['bank_branch']) : null,
                'account_number' => preg_replace('/\s+/', '', (string) $data['account_number']),
                'swift_code' => !empty($data['swift_code']) ? strtoupper(trim($data['swift_code'])) : null,
            ];
        }

        $summary = (new PayoutAccount($fields))->summary();
        if ($error = $this->codes->send($user, ActionCodes::PAYOUT_ACCOUNT, "add payout account {$summary}", $ip)) {
            throw new LiquidationException($error);
        }

        return [
            'landlord_id' => $landlord->id,
            'user_id' => $user->id,
            'fields' => $fields,
            'summary' => $summary,
            'expires_at' => now()->addMinutes(ActionCodes::CODE_MINUTES)->timestamp,
        ];
    }

    public function resendAccountCode(User $user, array $pending, ?string $ip): array
    {
        if ($error = $this->codes->send($user, ActionCodes::PAYOUT_ACCOUNT, "add payout account {$pending['summary']}", $ip)) {
            throw new LiquidationException($error);
        }

        return ['expires_at' => now()->addMinutes(ActionCodes::CODE_MINUTES)->timestamp] + $pending;
    }

    /** The code proves it's really the account holder; then the account is added (for Primora to verify). */
    public function confirmAccount(User $user, Landlord $landlord, array $pending, string $code): PayoutAccount
    {
        $this->verify($user, ActionCodes::PAYOUT_ACCOUNT, $code);
        $fields = $pending['fields'];

        // Lock the landlord row so two quick submissions can't both slip under the limit.
        $account = DB::transaction(function () use ($fields, $user, $landlord) {
            if ($fields['type'] === 'mobile_money') {
                Landlord::whereKey($landlord->id)->lockForUpdate()->first();
                if (PayoutAccount::mobileMoneyInUse() >= PayoutAccount::mobileMoneyLimit()) {
                    return null;
                }
            }

            return PayoutAccount::create($fields + ['status' => 'pending', 'created_by_user_id' => $user->id]);
        });

        if (!$account) {
            throw new LiquidationException($this->limitMessage());
        }
        $this->announceNewAccount($account->load('landlord'), $user);

        return $account;
    }

    /** A pending step is usable only by the user who started it, for the same landlord, before it expires. */
    public static function stillValid(mixed $pending, User $user, int $landlordId): bool
    {
        return is_array($pending) && ($pending['user_id'] ?? null) === $user->id
            && ($pending['landlord_id'] ?? null) === $landlordId && ($pending['expires_at'] ?? 0) >= now()->timestamp;
    }

    public function limitMessage(): string
    {
        $limit = PayoutAccount::mobileMoneyLimit();

        return "You can have up to {$limit} mobile money ".($limit === 1 ? 'number' : 'numbers').'. Remove one before adding another.';
    }

    protected function verify(User $user, string $purpose, string $code): void
    {
        if (!$this->codes->verify($user, $purpose, $code)) {
            throw ValidationException::withMessages(['code' => 'That code is wrong or has expired. Check it, or send a new code.']);
        }
    }

    /** Tell the landlord's admins (in case it wasn't them) and the staff who verify accounts. */
    protected function announceNewAccount(PayoutAccount $account, User $by): void
    {
        $landlord = $account->landlord;
        $admins = User::where('landlord_id', $landlord->id)->where('role', 'admin')->where('is_active', true)->pluck('email')
            ->push($landlord->contact_email)->filter()->unique()->values()->all();
        $verifiers = StaffUser::where('is_active', true)->get()->filter(fn (StaffUser $s) => $s->hasPermission('liquidations.approve'))->pluck('email')->all();

        try {
            if ($admins) {
                Mail::to($admins)->send(new NoticeMail(
                    subject: 'A payout account was added to your '.config('app.name').' account',
                    heading: 'New payout account added',
                    lines: [
                        "{$by->name} added a payout account: {$account->summary()}.",
                        'Primora will verify it before any money can be sent to it.',
                        "If you didn't expect this, remove it from Payouts and change your password straight away.",
                    ],
                    actionText: 'Review payout accounts',
                    actionUrl: route('payouts.index'),
                ));
            }
            if ($verifiers) {
                Mail::to($verifiers)->send(new NoticeMail(
                    subject: "Payout account to verify for {$landlord->name}",
                    heading: 'A payout account needs verifying',
                    lines: ["{$landlord->name} added: {$account->summary()}.", 'Confirm the account belongs to the landlord before verifying it.'],
                    actionText: 'Verify accounts',
                    actionUrl: route('staff.payout-accounts.index'),
                ));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
